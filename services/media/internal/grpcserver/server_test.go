package grpcserver_test

import (
	"bytes"
	"context"
	"errors"
	"image"
	"image/color"
	"image/png"
	"io"
	"net"
	"testing"

	_ "golang.org/x/image/webp" // register WebP decoder to verify generated thumbnails
	"google.golang.org/grpc"
	"google.golang.org/grpc/codes"
	"google.golang.org/grpc/credentials/insecure"
	"google.golang.org/grpc/status"
	"google.golang.org/grpc/test/bufconn"

	mediav1 "github.com/bookly/media/gen/media/v1"
	"github.com/bookly/media/internal/blob"
	"github.com/bookly/media/internal/grpcserver"
	"github.com/bookly/media/internal/media"
	"github.com/bookly/media/internal/thumbnail"
)

// newClient spins up the real gRPC server backed by srv over an in-memory
// bufconn pipe and returns a connected client. This exercises the WHOLE
// transport — real marshalling, real streaming — without opening a TCP port.
func newClient(t *testing.T, srv *grpcserver.Server) mediav1.MediaServiceClient {
	t.Helper()
	lis := bufconn.Listen(1 << 20)
	gs := grpc.NewServer()
	mediav1.RegisterMediaServiceServer(gs, srv)
	go func() { _ = gs.Serve(lis) }()

	conn, err := grpc.NewClient(
		"passthrough:///bufnet",
		grpc.WithContextDialer(func(ctx context.Context, _ string) (net.Conn, error) {
			return lis.DialContext(ctx)
		}),
		grpc.WithTransportCredentials(insecure.NewCredentials()),
	)
	if err != nil {
		t.Fatalf("dial: %v", err)
	}
	t.Cleanup(func() {
		_ = conn.Close()
		gs.Stop()
	})
	return mediav1.NewMediaServiceClient(conn)
}

// realServer wires the full stack: gRPC transport -> media.Service -> filesystem.
func realServer(t *testing.T) *grpcserver.Server {
	t.Helper()
	store, err := blob.NewFSStore(t.TempDir())
	if err != nil {
		t.Fatalf("NewFSStore: %v", err)
	}
	return grpcserver.New(media.NewService(store, thumbnail.New()))
}

func TestStoreStreamThenStatAndDelete(t *testing.T) {
	client := newClient(t, realServer(t))
	ctx := context.Background()

	// Upload "hello" as TWO chunks — the server must reassemble them via the
	// streamReader and report the total size.
	stream, err := client.Store(ctx)
	if err != nil {
		t.Fatalf("Store open: %v", err)
	}
	if err := stream.Send(&mediav1.StoreRequest{Key: "covers/x.jpg", Chunk: []byte("hel")}); err != nil {
		t.Fatalf("Send 1: %v", err)
	}
	if err := stream.Send(&mediav1.StoreRequest{Chunk: []byte("lo")}); err != nil {
		t.Fatalf("Send 2: %v", err)
	}
	resp, err := stream.CloseAndRecv()
	if err != nil {
		t.Fatalf("CloseAndRecv: %v", err)
	}
	if resp.GetSize() != 5 || resp.GetKey() != "covers/x.jpg" {
		t.Fatalf("Store resp = {%q, %d}, want {covers/x.jpg, 5}", resp.GetKey(), resp.GetSize())
	}

	// Stat sees it...
	st, err := client.Stat(ctx, &mediav1.StatRequest{Key: "covers/x.jpg"})
	if err != nil {
		t.Fatalf("Stat: %v", err)
	}
	if st.GetSize() != 5 {
		t.Fatalf("Stat size = %d, want 5", st.GetSize())
	}

	// ...Delete removes it, and Stat then reports NotFound.
	if _, err := client.Delete(ctx, &mediav1.DeleteRequest{Key: "covers/x.jpg"}); err != nil {
		t.Fatalf("Delete: %v", err)
	}
	_, err = client.Stat(ctx, &mediav1.StatRequest{Key: "covers/x.jpg"})
	if code(err) != codes.NotFound {
		t.Fatalf("Stat after Delete: code %v, want NotFound", code(err))
	}
}

func TestReadStreamsBytesBack(t *testing.T) {
	client := newClient(t, realServer(t))
	ctx := context.Background()
	store(t, client, "ebooks/42/file.epub", "the whole payload")

	stream, err := client.Read(ctx, &mediav1.ReadRequest{Key: "ebooks/42/file.epub"})
	if err != nil {
		t.Fatalf("Read: %v", err)
	}
	var got []byte
	for {
		resp, err := stream.Recv()
		if errors.Is(err, io.EOF) {
			break
		}
		if err != nil {
			t.Fatalf("Recv: %v", err)
		}
		got = append(got, resp.GetChunk()...)
	}
	if string(got) != "the whole payload" {
		t.Fatalf("Read reassembled %q, want %q", got, "the whole payload")
	}

	// Reading a missing key surfaces NotFound.
	miss, _ := client.Read(ctx, &mediav1.ReadRequest{Key: "nope"})
	_, err = miss.Recv()
	if code(err) != codes.NotFound {
		t.Fatalf("Read missing: code %v, want NotFound", code(err))
	}
}

// TestGenerateThumbnailsEndToEnd runs the full stack: upload a real PNG, ask the
// service (real thumbnail renderer) to make a 2:3 / 200px WebP variant, then read
// it back and decode it to confirm it is a valid 200x300 image at the derived key.
func TestGenerateThumbnailsEndToEnd(t *testing.T) {
	client := newClient(t, realServer(t))
	ctx := context.Background()

	// A 300x300 source; crop-to-fill 2:3 then resize to 200 wide → 200x300.
	src := image.NewRGBA(image.Rect(0, 0, 300, 300))
	for y := 0; y < 300; y++ {
		for x := 0; x < 300; x++ {
			src.Set(x, y, color.RGBA{R: uint8(x), G: uint8(y), B: 128, A: 255})
		}
	}
	var buf bytes.Buffer
	if err := png.Encode(&buf, src); err != nil {
		t.Fatalf("encode png: %v", err)
	}
	store(t, client, "covers/xyz/original.png", buf.String())

	resp, err := client.GenerateThumbnails(ctx, &mediav1.GenerateThumbnailsRequest{
		SourceKey: "covers/xyz/original.png",
		Format:    "webp",
		Quality:   80,
		Specs:     []*mediav1.ThumbnailSpec{{AspectW: 2, AspectH: 3, Width: 200}},
	})
	if err != nil {
		t.Fatalf("GenerateThumbnails: %v", err)
	}
	if len(resp.GetThumbnails()) != 1 {
		t.Fatalf("got %d thumbnails, want 1", len(resp.GetThumbnails()))
	}
	th := resp.GetThumbnails()[0]
	if th.GetWidth() != 200 || th.GetHeight() != 300 {
		t.Fatalf("dims %dx%d, want 200x300", th.GetWidth(), th.GetHeight())
	}
	if th.GetKey() != "covers/xyz/original_w200.webp" {
		t.Fatalf("key = %q, want covers/xyz/original_w200.webp", th.GetKey())
	}

	// Read the variant back and decode it — proves it is a real, valid WebP.
	got := readKey(t, client, th.GetKey())
	img, format, err := image.Decode(bytes.NewReader(got))
	if err != nil {
		t.Fatalf("decode thumbnail: %v", err)
	}
	if format != "webp" {
		t.Fatalf("decoded format = %q, want webp", format)
	}
	if img.Bounds().Dx() != 200 || img.Bounds().Dy() != 300 {
		t.Fatalf("decoded bounds = %v, want 200x300", img.Bounds())
	}
}

func TestMoveAndDirectories(t *testing.T) {
	client := newClient(t, realServer(t))
	ctx := context.Background()

	store(t, client, "staging/tmp", "payload")
	if _, err := client.Move(ctx, &mediav1.MoveRequest{Source: "staging/tmp", Destination: "ebooks/42/final.epub"}); err != nil {
		t.Fatalf("Move: %v", err)
	}

	dirs, err := client.Directories(ctx, &mediav1.DirectoriesRequest{Prefix: "ebooks"})
	if err != nil {
		t.Fatalf("Directories: %v", err)
	}
	if len(dirs.GetNames()) != 1 || dirs.GetNames()[0] != "42" {
		t.Fatalf("Directories = %v, want [42]", dirs.GetNames())
	}
}

func TestInvalidKeyMapsToInvalidArgument(t *testing.T) {
	client := newClient(t, realServer(t))

	_, err := client.Stat(context.Background(), &mediav1.StatRequest{Key: "../escape"})
	if code(err) != codes.InvalidArgument {
		t.Fatalf("code %v, want InvalidArgument", code(err))
	}
}

// TestInternalErrorDoesNotLeak proves an unexpected error becomes a GENERIC
// Internal status: the client sees codes.Internal with a scrubbed message, never
// the underlying cause (which could contain a storage path).
func TestInternalErrorDoesNotLeak(t *testing.T) {
	client := newClient(t, grpcserver.New(boomService{}))

	_, err := client.Stat(context.Background(), &mediav1.StatRequest{Key: "whatever"})
	st, _ := status.FromError(err)
	if st.Code() != codes.Internal {
		t.Fatalf("code %v, want Internal", st.Code())
	}
	if st.Message() != "media: internal error" {
		t.Fatalf("message %q leaked internals; want the scrubbed generic message", st.Message())
	}
}

// --- helpers ---

func code(err error) codes.Code {
	st, _ := status.FromError(err)
	return st.Code()
}

func readKey(t *testing.T, client mediav1.MediaServiceClient, key string) []byte {
	t.Helper()
	stream, err := client.Read(context.Background(), &mediav1.ReadRequest{Key: key})
	if err != nil {
		t.Fatalf("Read %q: %v", key, err)
	}
	var out []byte
	for {
		resp, err := stream.Recv()
		if errors.Is(err, io.EOF) {
			break
		}
		if err != nil {
			t.Fatalf("Recv %q: %v", key, err)
		}
		out = append(out, resp.GetChunk()...)
	}
	return out
}

func store(t *testing.T, client mediav1.MediaServiceClient, key, body string) {
	t.Helper()
	stream, err := client.Store(context.Background())
	if err != nil {
		t.Fatalf("Store open: %v", err)
	}
	if err := stream.Send(&mediav1.StoreRequest{Key: key, Chunk: []byte(body)}); err != nil {
		t.Fatalf("Send: %v", err)
	}
	if _, err := stream.CloseAndRecv(); err != nil {
		t.Fatalf("CloseAndRecv: %v", err)
	}
}

// boomService satisfies the (unexported) service interface consumed by New and
// fails every call with a would-be-leaky error, to test error scrubbing.
type boomService struct{}

var errBoom = errors.New("boom: /var/media/secret/path")

func (boomService) Store(context.Context, string, io.Reader) (media.Info, error) {
	return media.Info{}, errBoom
}
func (boomService) Open(context.Context, string) (io.ReadCloser, error) { return nil, errBoom }
func (boomService) Stat(context.Context, string) (media.Info, error)    { return media.Info{}, errBoom }
func (boomService) Delete(context.Context, string) error                { return errBoom }
func (boomService) Move(context.Context, string, string) error          { return errBoom }
func (boomService) DeleteDirectory(context.Context, string) error       { return errBoom }
func (boomService) Directories(context.Context, string) ([]string, error) {
	return nil, errBoom
}
func (boomService) GenerateThumbnails(context.Context, string, string, uint32, []media.ThumbnailSpec) ([]media.Variant, error) {
	return nil, errBoom
}
