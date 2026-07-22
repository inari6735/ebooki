// Package media is the core of the media service. It owns the domain rules and
// delegates byte persistence to a Blobstore. It knows NOTHING about gRPC, HTTP or
// the filesystem — those live in other packages and depend on this one, never the
// other way round. That dependency direction is what keeps the core testable and
// the transport swappable.
package media

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"io"
	"path"
	"strings"
)

// ErrInvalidKey is returned when a caller supplies an unsafe or empty key. Like
// ErrNotFound it is a sentinel, checked with errors.Is.
var ErrInvalidKey = errors.New("media: invalid key")

// Service is the entry point to the core logic. It is a concrete struct with
// unexported fields — callers build one with NewService and cannot reach inside.
//
// Note the idiom pair: NewService ACCEPTS an interface (Blobstore, the
// abstraction it needs) and the constructor RETURNS a concrete *Service. That is
// "accept interfaces, return structs".
type Service struct {
	blobs  Blobstore
	thumbs Thumbnailer
}

// NewService wires the Service with its dependencies. In Go this "constructor" is
// just a function named NewXxx — there is no framework, no DI container. The real
// wiring happens once, in main().
func NewService(blobs Blobstore, thumbs Thumbnailer) *Service {
	return &Service{blobs: blobs, thumbs: thumbs}
}

// Store validates the key, then streams r into the blob store. It returns the
// resulting blob metadata so the caller (e.g. Symfony, via the transport layer)
// can persist size without a second round-trip.
func (s *Service) Store(ctx context.Context, key string, r io.Reader) (Info, error) {
	if err := validateKey(key); err != nil {
		return Info{}, err
	}
	size, err := s.blobs.Put(ctx, key, r)
	if err != nil {
		// Wrap with %w: the caller can still errors.Is(err, ...) the cause, but
		// now the message says which operation and key failed.
		return Info{}, fmt.Errorf("media: store %q: %w", key, err)
	}
	return Info{Key: key, Size: size}, nil
}

// Open returns a reader for the blob. The CALLER owns the returned ReadCloser and
// must Close it — the core does not read the bytes itself, so the transport layer
// can stream straight through without buffering the whole file in memory.
func (s *Service) Open(ctx context.Context, key string) (io.ReadCloser, error) {
	if err := validateKey(key); err != nil {
		return nil, err
	}
	rc, err := s.blobs.Open(ctx, key)
	if err != nil {
		return nil, fmt.Errorf("media: open %q: %w", key, err)
	}
	return rc, nil
}

// Stat returns metadata without touching the bytes.
func (s *Service) Stat(ctx context.Context, key string) (Info, error) {
	if err := validateKey(key); err != nil {
		return Info{}, err
	}
	info, err := s.blobs.Stat(ctx, key)
	if err != nil {
		return Info{}, fmt.Errorf("media: stat %q: %w", key, err)
	}
	return info, nil
}

// Delete removes a blob. It is idempotent (deleting a missing key is fine), so
// callers can retry safely.
func (s *Service) Delete(ctx context.Context, key string) error {
	if err := validateKey(key); err != nil {
		return err
	}
	if err := s.blobs.Delete(ctx, key); err != nil {
		return fmt.Errorf("media: delete %q: %w", key, err)
	}
	return nil
}

// Move relocates a blob, e.g. from a staging key to its final key after an upload
// is validated. Both ends are validated before touching storage.
func (s *Service) Move(ctx context.Context, src, dst string) error {
	if err := validateKey(src); err != nil {
		return fmt.Errorf("media: move source: %w", err)
	}
	if err := validateKey(dst); err != nil {
		return fmt.Errorf("media: move destination: %w", err)
	}
	if err := s.blobs.Move(ctx, src, dst); err != nil {
		return fmt.Errorf("media: move %q to %q: %w", src, dst, err)
	}
	return nil
}

// DeleteDirectory removes everything under a prefix (e.g. all files of one
// eBook). It reuses validateKey, which REJECTS an empty prefix — deleting the
// whole store must never be one accidental empty string away.
func (s *Service) DeleteDirectory(ctx context.Context, prefix string) error {
	if err := validateKey(prefix); err != nil {
		return err
	}
	if err := s.blobs.DeleteDirectory(ctx, prefix); err != nil {
		return fmt.Errorf("media: delete directory %q: %w", prefix, err)
	}
	return nil
}

// Directories lists immediate child directory names under prefix. Unlike the
// mutating operations it TOLERATES an empty prefix, meaning "list the root" —
// that is exactly what reconciliation needs to enumerate all media.
func (s *Service) Directories(ctx context.Context, prefix string) ([]string, error) {
	if err := validatePrefix(prefix); err != nil {
		return nil, err
	}
	dirs, err := s.blobs.Directories(ctx, prefix)
	if err != nil {
		return nil, fmt.Errorf("media: list directories %q: %w", prefix, err)
	}
	return dirs, nil
}

// GenerateThumbnails reads the source blob once, renders every requested variant
// via the Thumbnailer, and writes each under a deterministic key next to the
// source. It returns exactly what was written so the caller can persist it. It
// does NOT touch the source. Re-running overwrites variants in place (idempotent).
func (s *Service) GenerateThumbnails(ctx context.Context, sourceKey, format string, quality uint32, specs []ThumbnailSpec) ([]Variant, error) {
	if err := validateKey(sourceKey); err != nil {
		return nil, err
	}
	if len(specs) == 0 {
		return nil, nil
	}

	rc, err := s.blobs.Open(ctx, sourceKey)
	if err != nil {
		return nil, fmt.Errorf("media: thumbnails open %q: %w", sourceKey, err)
	}
	// Covers are small, so reading the whole source into memory is fine and lets
	// the Thumbnailer decode it once for all specs.
	source, err := io.ReadAll(rc)
	_ = rc.Close()
	if err != nil {
		return nil, fmt.Errorf("media: thumbnails read %q: %w", sourceKey, err)
	}

	rendered, err := s.thumbs.Render(source, format, quality, specs)
	if err != nil {
		return nil, fmt.Errorf("media: thumbnails render %q: %w", sourceKey, err)
	}

	variants := make([]Variant, 0, len(rendered))
	for _, r := range rendered {
		key := thumbKey(sourceKey, r.Width, format)
		size, err := s.blobs.Put(ctx, key, bytes.NewReader(r.Data))
		if err != nil {
			return nil, fmt.Errorf("media: thumbnails put %q: %w", key, err)
		}
		variants = append(variants, Variant{Key: key, Width: r.Width, Height: r.Height, Size: size})
	}
	return variants, nil
}

// thumbKey derives a deterministic variant key from the source key, so
// regeneration overwrites in place, e.g.
// covers/{id}/original.jpg + (320, webp) -> covers/{id}/original_w320.webp.
func thumbKey(sourceKey string, width uint32, format string) string {
	ext := path.Ext(sourceKey)
	base := strings.TrimSuffix(sourceKey, ext)
	return fmt.Sprintf("%s_w%d.%s", base, width, format)
}

// validateKey enforces the one genuine invariant of this domain: a key is a safe,
// relative, forward-slash path. This is defence-in-depth — the filesystem adapter
// will also refuse traversal, but rejecting it HERE means every transport and
// every storage backend inherits the guarantee for free.
//
// It is unexported (lowercase) because it is an internal rule, not part of the
// package's public surface; we test it through the exported methods.
func validateKey(key string) error {
	if key == "" {
		return fmt.Errorf("%w: must not be empty", ErrInvalidKey)
	}
	return validatePrefix(key)
}

// validatePrefix is the shared path-safety check: relative, no traversal. It
// permits an empty string (the root) so it can back both single-key operations
// (via validateKey, which adds the non-empty rule) and root-tolerant listing.
func validatePrefix(prefix string) error {
	switch {
	case strings.HasPrefix(prefix, "/"):
		return fmt.Errorf("%w: must be relative, got %q", ErrInvalidKey, prefix)
	case strings.Contains(prefix, ".."):
		return fmt.Errorf("%w: must not contain %q, got %q", ErrInvalidKey, "..", prefix)
	}
	return nil
}
