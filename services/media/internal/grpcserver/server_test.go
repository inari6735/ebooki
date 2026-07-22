package grpcserver_test

import (
	"context"
	"errors"
	"io"
	"net"
	"testing"

	"google.golang.org/grpc"
	"google.golang.org/grpc/codes"
	"google.golang.org/grpc/credentials/insecure"
	"google.golang.org/grpc/status"
	"google.golang.org/grpc/test/bufconn"

	mediav1 "github.com/bookly/media/gen/media/v1"
	"github.com/bookly/media/internal/blob"
	"github.com/bookly/media/internal/grpcserver"
	"github.com/bookly/media/internal/media"
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
	return grpcserver.New(media.NewService(store))
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
