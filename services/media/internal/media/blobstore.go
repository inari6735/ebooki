package media

import (
	"context"
	"errors"
	"io"
)

// ErrNotFound is returned by a Blobstore (and surfaced by Service) when a key
// does not exist. It is a *sentinel* error: callers check it with errors.Is,
// never by comparing strings. Wrapping it with fmt.Errorf("...: %w", ErrNotFound)
// keeps errors.Is working while adding context.
var ErrNotFound = errors.New("media: blob not found")

// Info is the metadata the service knows about a stored blob. Deliberately a
// plain value type with no behaviour — it crosses package boundaries freely.
type Info struct {
	Key  string
	Size int64
}

// Blobstore is the persistence port the media Service depends on.
//
// It is defined HERE, next to its only consumer (the Service), not next to the
// implementation — this is the core Go idiom: "the consumer owns the interface".
// It is kept small on purpose; a filesystem adapter, an S3 adapter or an
// in-memory fake for tests all satisfy it *implicitly* (no `implements` keyword),
// simply by having these methods.
//
// Every method takes a context.Context first so callers can cancel slow I/O and
// carry deadlines — the standard shape for anything that touches the network or
// disk.
type Blobstore interface {
	// Put streams r into storage under key and returns the number of bytes written.
	Put(ctx context.Context, key string, r io.Reader) (int64, error)

	// Open returns a reader for the blob at key. The caller MUST Close it.
	// Returns ErrNotFound if key does not exist.
	Open(ctx context.Context, key string) (io.ReadCloser, error)

	// Stat returns metadata without reading the bytes. Returns ErrNotFound if
	// key does not exist.
	Stat(ctx context.Context, key string) (Info, error)

	// Delete removes the blob at key. Deleting a missing key is not an error
	// (idempotent), so retries are safe.
	Delete(ctx context.Context, key string) error

	// Move relocates the blob from src to dst, creating any parent "directories"
	// dst needs. Returns ErrNotFound if src does not exist. Used by the staging
	// upload flow (write to a staging key, then move into the final key).
	Move(ctx context.Context, src, dst string) error

	// DeleteDirectory removes every blob under the prefix, recursively. It is
	// idempotent (a missing prefix is fine). Implementations MUST refuse to
	// delete the storage root itself.
	DeleteDirectory(ctx context.Context, prefix string) error

	// Directories lists the names of the immediate child "directories" under
	// prefix (an empty prefix means the storage root). A missing prefix yields
	// an empty list, not an error. Used to reconcile orphaned media.
	Directories(ctx context.Context, prefix string) ([]string, error)
}
