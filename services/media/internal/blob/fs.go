// Package blob implements media.Blobstore on the local filesystem. It is an
// INFRASTRUCTURE adapter: it depends on the core package (media) to speak its
// language — media.Info, media.ErrNotFound — never the other way round. The core
// has no idea this package exists.
package blob

import (
	"context"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"strings"

	"github.com/bookly/media/internal/media"
)

// FSStore stores each blob as a file under a root directory. A key like
// "covers/ab/cd.jpg" maps to <root>/covers/ab/cd.jpg.
type FSStore struct {
	root string
}

// NewFSStore returns a store rooted at dir, creating dir if it does not exist.
// It returns a concrete *FSStore — "return structs" — while its consumer
// (media.Service) only ever sees it as the media.Blobstore interface.
func NewFSStore(dir string) (*FSStore, error) {
	if err := os.MkdirAll(dir, 0o755); err != nil {
		return nil, fmt.Errorf("blob: create root %q: %w", dir, err)
	}
	return &FSStore{root: dir}, nil
}

// Compile-time proof that *FSStore satisfies media.Blobstore. This assignment to
// the blank identifier does nothing at runtime, but if the interface and the
// struct ever drift, the build fails HERE with a precise message instead of at a
// distant wiring site. This is the idiomatic way to pin an implicit interface.
var _ media.Blobstore = (*FSStore)(nil)

func (s *FSStore) Put(_ context.Context, key string, r io.Reader) (int64, error) {
	full, err := s.resolve(key)
	if err != nil {
		return 0, err
	}
	if err := os.MkdirAll(filepath.Dir(full), 0o755); err != nil {
		return 0, fmt.Errorf("blob: mkdir for %q: %w", key, err)
	}

	// Write to a temp file in the SAME directory, then rename it into place.
	// Rename is atomic on one filesystem, so a concurrent reader never sees a
	// half-written blob, and a crash mid-write leaves only a stray temp file —
	// never a corrupt file masquerading as a valid key.
	tmp, err := os.CreateTemp(filepath.Dir(full), ".tmp-*")
	if err != nil {
		return 0, fmt.Errorf("blob: temp file for %q: %w", key, err)
	}
	tmpName := tmp.Name()
	// Best-effort cleanup if we bail before the rename succeeds. After a
	// successful rename tmpName no longer exists and this Remove is a harmless
	// no-op whose error we intentionally drop.
	defer func() { _ = os.Remove(tmpName) }()

	n, err := io.Copy(tmp, r)
	if err != nil {
		_ = tmp.Close()
		return 0, fmt.Errorf("blob: write %q: %w", key, err)
	}
	if err := tmp.Close(); err != nil {
		return 0, fmt.Errorf("blob: close temp for %q: %w", key, err)
	}
	if err := os.Rename(tmpName, full); err != nil {
		return 0, fmt.Errorf("blob: commit %q: %w", key, err)
	}
	return n, nil
}

func (s *FSStore) Open(_ context.Context, key string) (io.ReadCloser, error) {
	full, err := s.resolve(key)
	if err != nil {
		return nil, err
	}
	f, err := os.Open(full)
	if errors.Is(err, os.ErrNotExist) {
		// Translate the OS-level error into the DOMAIN sentinel, so callers stay
		// decoupled from the filesystem and can errors.Is(err, media.ErrNotFound).
		return nil, media.ErrNotFound
	}
	if err != nil {
		return nil, fmt.Errorf("blob: open %q: %w", key, err)
	}
	return f, nil // *os.File is an io.ReadCloser; the CALLER closes it.
}

func (s *FSStore) Stat(_ context.Context, key string) (media.Info, error) {
	full, err := s.resolve(key)
	if err != nil {
		return media.Info{}, err
	}
	fi, err := os.Stat(full)
	if errors.Is(err, os.ErrNotExist) {
		return media.Info{}, media.ErrNotFound
	}
	if err != nil {
		return media.Info{}, fmt.Errorf("blob: stat %q: %w", key, err)
	}
	return media.Info{Key: key, Size: fi.Size()}, nil
}

func (s *FSStore) Delete(_ context.Context, key string) error {
	full, err := s.resolve(key)
	if err != nil {
		return err
	}
	// Idempotent: a missing key is not an error, so retries and double-deletes
	// are safe. Any OTHER error is real and surfaces.
	if err := os.Remove(full); err != nil && !errors.Is(err, os.ErrNotExist) {
		return fmt.Errorf("blob: delete %q: %w", key, err)
	}
	return nil
}

func (s *FSStore) Move(_ context.Context, src, dst string) error {
	from, err := s.resolve(src)
	if err != nil {
		return err
	}
	to, err := s.resolve(dst)
	if err != nil {
		return err
	}
	// Create the destination's parents first, so a "not exist" from Rename can
	// only mean the SOURCE is missing — which we map to the domain sentinel.
	if err := os.MkdirAll(filepath.Dir(to), 0o755); err != nil {
		return fmt.Errorf("blob: mkdir for %q: %w", dst, err)
	}
	err = os.Rename(from, to)
	if errors.Is(err, os.ErrNotExist) {
		return media.ErrNotFound
	}
	if err != nil {
		return fmt.Errorf("blob: move %q to %q: %w", src, dst, err)
	}
	return nil
}

func (s *FSStore) DeleteDirectory(_ context.Context, prefix string) error {
	full, err := s.resolve(prefix)
	if err != nil {
		return err
	}
	// Defence in depth: the core already rejects an empty prefix, but never let a
	// direct caller RemoveAll the entire store.
	if full == s.root {
		return fmt.Errorf("blob: refusing to delete storage root")
	}
	// RemoveAll is recursive and idempotent (no error if the path is absent).
	if err := os.RemoveAll(full); err != nil {
		return fmt.Errorf("blob: delete directory %q: %w", prefix, err)
	}
	return nil
}

func (s *FSStore) Directories(_ context.Context, prefix string) ([]string, error) {
	full, err := s.resolve(prefix)
	if err != nil {
		return nil, err
	}
	entries, err := os.ReadDir(full)
	if errors.Is(err, os.ErrNotExist) {
		return nil, nil // a missing prefix is an empty listing, not an error
	}
	if err != nil {
		return nil, fmt.Errorf("blob: list directories %q: %w", prefix, err)
	}
	var dirs []string
	for _, e := range entries {
		if e.IsDir() {
			dirs = append(dirs, e.Name())
		}
	}
	return dirs, nil
}

// resolve maps a storage key to an absolute filesystem path and guarantees the
// result stays inside root. The core already validates keys, but an adapter must
// never trust its caller — defence in depth.
//
// The trick: prefixing with a separator and cleaning collapses any ".." so it can
// never climb ABOVE root (at worst it lands somewhere else inside root). The
// explicit prefix check is a belt-and-suspenders guard on top of that.
func (s *FSStore) resolve(key string) (string, error) {
	clean := filepath.Clean(string(os.PathSeparator) + filepath.FromSlash(key))
	full := filepath.Join(s.root, clean)
	if full != s.root && !strings.HasPrefix(full, s.root+string(os.PathSeparator)) {
		return "", fmt.Errorf("blob: key %q escapes storage root", key)
	}
	return full, nil
}
