// Command media is the entry point for the media service. It is deliberately
// THIN: read config, wire the dependencies once, start the gRPC server, and shut
// it down cleanly on a signal. Every piece of logic lives in the internal
// packages — main only assembles them. This is the whole "dependency injection
// framework" a Go service needs.
package main

import (
	"context"
	"fmt"
	"log/slog"
	"net"
	"os"
	"os/signal"
	"syscall"

	"google.golang.org/grpc"
	"google.golang.org/grpc/health"
	healthpb "google.golang.org/grpc/health/grpc_health_v1"
	"google.golang.org/grpc/reflection"

	mediav1 "github.com/bookly/media/gen/media/v1"
	"github.com/bookly/media/internal/blob"
	"github.com/bookly/media/internal/grpcserver"
	"github.com/bookly/media/internal/media"
)

func main() {
	// main() can't return an error, and os.Exit skips deferred cleanups — so all
	// real work goes in run(), and main only reports the outcome.
	if err := run(); err != nil {
		slog.Error("media service stopped", "err", err)
		os.Exit(1)
	}
}

func run() error {
	cfg := loadConfig()

	// Structured JSON logs to stdout — the platform (Upsun) collects them.
	slog.SetDefault(slog.New(slog.NewJSONHandler(os.Stdout, nil)))

	// --- WIRING: the single place dependencies are assembled ---
	// blob.NewFSStore returns a concrete *FSStore; media.NewService accepts it as
	// the media.Blobstore interface; grpcserver.New accepts the *Service as its
	// own narrow interface. Each layer only sees the abstraction it needs.
	store, err := blob.NewFSStore(cfg.StorageRoot)
	if err != nil {
		return fmt.Errorf("init storage at %q: %w", cfg.StorageRoot, err)
	}
	svc := media.NewService(store)
	srv := grpcserver.New(svc)

	gs := grpc.NewServer()
	mediav1.RegisterMediaServiceServer(gs, srv)

	// Health service: orchestrators (Upsun/k8s) probe this for readiness.
	healthSrv := health.NewServer()
	healthpb.RegisterHealthServer(gs, healthSrv)
	healthSrv.SetServingStatus(mediav1.MediaService_ServiceDesc.ServiceName, healthpb.HealthCheckResponse_SERVING)

	// Reflection lets tools like grpcurl introspect the API without the .proto —
	// invaluable for debugging. Safe on a private, internal-only service.
	reflection.Register(gs)

	lis, err := net.Listen("tcp", cfg.Addr)
	if err != nil {
		return fmt.Errorf("listen on %q: %w", cfg.Addr, err)
	}

	// --- GRACEFUL SHUTDOWN ---
	// NotifyContext cancels ctx on SIGINT/SIGTERM (what Upsun/Docker send to stop
	// a container), so in-flight RPCs finish instead of being cut off.
	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()

	serveErr := make(chan error, 1)
	go func() {
		slog.Info("media service listening", "addr", cfg.Addr, "storage", cfg.StorageRoot)
		serveErr <- gs.Serve(lis)
	}()

	select {
	case err := <-serveErr: // Serve returned on its own — a real failure.
		return fmt.Errorf("serve: %w", err)
	case <-ctx.Done(): // signal received — drain and exit cleanly.
		slog.Info("shutdown signal received, draining connections")
		gs.GracefulStop()
		slog.Info("media service stopped cleanly")
		return nil
	}
}
