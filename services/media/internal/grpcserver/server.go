// Package grpcserver adapts the generated gRPC MediaService API to the core
// media.Service. It is the TRANSPORT layer and the ONLY place that knows about
// protobuf: it converts wire messages to/from the core's plain Go types and maps
// domain errors to gRPC status codes. The core has no idea this package exists —
// swapping it for a REST handler would not touch a line of media/.
package grpcserver

import (
	"context"
	"errors"
	"io"
	"log/slog"

	"google.golang.org/grpc/codes"
	"google.golang.org/grpc/status"

	mediav1 "github.com/bookly/media/gen/media/v1"
	"github.com/bookly/media/internal/media"
)

// service is the slice of the core the transport actually uses. Defining it HERE,
// at the consumer, lets the server be tested against a fake and keeps it from
// hard-depending on the concrete *media.Service. *media.Service satisfies it
// implicitly.
type service interface {
	Store(ctx context.Context, key string, r io.Reader) (media.Info, error)
	Open(ctx context.Context, key string) (io.ReadCloser, error)
	Stat(ctx context.Context, key string) (media.Info, error)
	Delete(ctx context.Context, key string) error
	Move(ctx context.Context, src, dst string) error
	DeleteDirectory(ctx context.Context, prefix string) error
	Directories(ctx context.Context, prefix string) ([]string, error)
	GenerateThumbnails(ctx context.Context, sourceKey, format string, quality uint32, specs []media.ThumbnailSpec) ([]media.Variant, error)
}

// Server implements the generated MediaServiceServer by delegating to the core.
type Server struct {
	// Embedding the generated Unimplemented type is REQUIRED and forward-
	// compatible: if the .proto gains a new RPC, this still builds (the new
	// method is inherited, returning Unimplemented) instead of breaking.
	mediav1.UnimplementedMediaServiceServer
	svc service
}

func New(svc service) *Server {
	return &Server{svc: svc}
}

// Compile-time proof the handler set is complete.
var _ mediav1.MediaServiceServer = (*Server)(nil)

// Store consumes the client stream and feeds it to the core as a plain io.Reader,
// so the whole file never sits in memory. The first message carries the key.
func (s *Server) Store(stream mediav1.MediaService_StoreServer) error {
	first, err := stream.Recv()
	if err != nil {
		return status.Error(codes.InvalidArgument, "store: expected at least one message carrying the key")
	}

	r := &streamReader{stream: stream, buf: first.GetChunk()}
	info, err := s.svc.Store(stream.Context(), first.GetKey(), r)
	if err != nil {
		return toStatus(err)
	}
	return stream.SendAndClose(&mediav1.StoreResponse{Key: info.Key, Size: info.Size})
}

// Read streams the blob's bytes back to the client in chunks. The core hands us
// an io.ReadCloser; we pump it into the gRPC stream and always close it. This is
// the server-streaming mirror of Store's client streaming.
func (s *Server) Read(req *mediav1.ReadRequest, stream mediav1.MediaService_ReadServer) error {
	rc, err := s.svc.Open(stream.Context(), req.GetKey())
	if err != nil {
		return toStatus(err)
	}
	defer rc.Close()

	buf := make([]byte, 64*1024)
	for {
		n, err := rc.Read(buf)
		if n > 0 {
			if sendErr := stream.Send(&mediav1.ReadResponse{Chunk: buf[:n]}); sendErr != nil {
				return sendErr
			}
		}
		if errors.Is(err, io.EOF) {
			return nil
		}
		if err != nil {
			return toStatus(err)
		}
	}
}

func (s *Server) Stat(ctx context.Context, req *mediav1.StatRequest) (*mediav1.StatResponse, error) {
	info, err := s.svc.Stat(ctx, req.GetKey())
	if err != nil {
		return nil, toStatus(err)
	}
	return &mediav1.StatResponse{Key: info.Key, Size: info.Size}, nil
}

func (s *Server) Delete(ctx context.Context, req *mediav1.DeleteRequest) (*mediav1.DeleteResponse, error) {
	if err := s.svc.Delete(ctx, req.GetKey()); err != nil {
		return nil, toStatus(err)
	}
	return &mediav1.DeleteResponse{}, nil
}

func (s *Server) Move(ctx context.Context, req *mediav1.MoveRequest) (*mediav1.MoveResponse, error) {
	if err := s.svc.Move(ctx, req.GetSource(), req.GetDestination()); err != nil {
		return nil, toStatus(err)
	}
	return &mediav1.MoveResponse{}, nil
}

func (s *Server) DeleteDirectory(ctx context.Context, req *mediav1.DeleteDirectoryRequest) (*mediav1.DeleteDirectoryResponse, error) {
	if err := s.svc.DeleteDirectory(ctx, req.GetPrefix()); err != nil {
		return nil, toStatus(err)
	}
	return &mediav1.DeleteDirectoryResponse{}, nil
}

func (s *Server) Directories(ctx context.Context, req *mediav1.DirectoriesRequest) (*mediav1.DirectoriesResponse, error) {
	names, err := s.svc.Directories(ctx, req.GetPrefix())
	if err != nil {
		return nil, toStatus(err)
	}
	return &mediav1.DirectoriesResponse{Names: names}, nil
}

// streamReader turns the inbound gRPC client stream into an io.Reader: it yields
// the buffered chunk, then pulls the next message on demand, until the client
// half-closes (io.EOF). This is the bridge between "wire framing" and the plain
// io.Reader the core speaks.
type streamReader struct {
	stream mediav1.MediaService_StoreServer
	buf    []byte
	done   bool
}

func (r *streamReader) Read(p []byte) (int, error) {
	for len(r.buf) == 0 {
		if r.done {
			return 0, io.EOF
		}
		msg, err := r.stream.Recv()
		if errors.Is(err, io.EOF) {
			r.done = true
			continue
		}
		if err != nil {
			return 0, err
		}
		r.buf = msg.GetChunk()
	}
	n := copy(p, r.buf)
	r.buf = r.buf[n:]
	return n, nil
}

func (s *Server) GenerateThumbnails(ctx context.Context, req *mediav1.GenerateThumbnailsRequest) (*mediav1.GenerateThumbnailsResponse, error) {
	specs := make([]media.ThumbnailSpec, 0, len(req.GetSpecs()))
	for _, sp := range req.GetSpecs() {
		specs = append(specs, media.ThumbnailSpec{
			AspectW: sp.GetAspectW(),
			AspectH: sp.GetAspectH(),
			Width:   sp.GetWidth(),
		})
	}

	variants, err := s.svc.GenerateThumbnails(ctx, req.GetSourceKey(), req.GetFormat(), req.GetQuality(), specs)
	if err != nil {
		return nil, toStatus(err)
	}

	out := make([]*mediav1.Thumbnail, 0, len(variants))
	for _, v := range variants {
		out = append(out, &mediav1.Thumbnail{
			Key:       v.Key,
			Width:     v.Width,
			Height:    v.Height,
			SizeBytes: v.Size,
		})
	}
	return &mediav1.GenerateThumbnailsResponse{Thumbnails: out}, nil
}

// toStatus is the SINGLE place that translates the core's error vocabulary into
// gRPC status codes. Known domain errors map to precise codes; anything else
// becomes Internal with a GENERIC message, so storage paths and internals never
// leak to the client (the detailed cause belongs in server logs, not the wire).
func toStatus(err error) error {
	switch {
	case err == nil:
		return nil
	case errors.Is(err, media.ErrNotFound):
		return status.Error(codes.NotFound, "media: not found")
	case errors.Is(err, media.ErrInvalidKey):
		return status.Error(codes.InvalidArgument, "media: invalid key")
	default:
		// Scrub the client-facing message (never leak paths/internals), but LOG
		// the real cause server-side so failures are actually diagnosable.
		slog.Error("media: internal error", "err", err)
		return status.Error(codes.Internal, "media: internal error")
	}
}
