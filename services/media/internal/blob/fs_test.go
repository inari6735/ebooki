package blob_test

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/bookly/media/internal/blob"
	"github.com/bookly/media/internal/media"
)

// newStore builds an FSStore rooted at a throwaway temp dir. t.TempDir() is
// created fresh per test and removed automatically when the test ends — no
// manual cleanup, no shared state between tests.
func newStore(t *testing.T) *blob.FSStore {
	t.Helper()
	s, err := blob.NewFSStore(t.TempDir())
	if err != nil {
		t.Fatalf("NewFSStore: %v", err)
	}
	return s
}

func TestPutOpenRoundTrip(t *testing.T) {
	s := newStore(t)
	ctx := context.Background()
	const key, body = "covers/ab/cd.jpg", "the bytes"

	// A nested key must create its parent directories on the way in.
	n, err := s.Put(ctx, key, strings.NewReader(body))
	if err != nil {
		t.Fatalf("Put: %v", err)
	}
	if n != int64(len(body)) {
		t.Fatalf("Put returned %d, want %d", n, len(body))
	}

	rc, err := s.Open(ctx, key)
	if err != nil {
		t.Fatalf("Open: %v", err)
	}
	defer rc.Close()
	got := readAll(t, rc)
	if got != body {
		t.Fatalf("Open returned %q, want %q", got, body)
	}
}

// TestPutIsAtomic checks the temp-file-then-rename strategy leaves no ".tmp-*"
// litter behind after a successful write.
func TestPutIsAtomic(t *testing.T) {
	root := t.TempDir()
	s, _ := blob.NewFSStore(root)

	if _, err := s.Put(context.Background(), "a/b.txt", strings.NewReader("x")); err != nil {
		t.Fatalf("Put: %v", err)
	}
	entries, _ := os.ReadDir(filepath.Join(root, "a"))
	for _, e := range entries {
		if strings.HasPrefix(e.Name(), ".tmp-") {
			t.Fatalf("temp file left behind: %s", e.Name())
		}
	}
}

func TestOpenAndStatMissingKey(t *testing.T) {
	s := newStore(t)
	ctx := context.Background()

	// Both read paths must translate a missing file into the DOMAIN sentinel,
	// not leak an *os.PathError. Table-driven over the two operations.
	ops := []struct {
		name string
		call func() error
	}{
		{"Open", func() error { _, err := s.Open(ctx, "nope"); return err }},
		{"Stat", func() error { _, err := s.Stat(ctx, "nope"); return err }},
	}
	for _, op := range ops {
		t.Run(op.name, func(t *testing.T) {
			if err := op.call(); !errors.Is(err, media.ErrNotFound) {
				t.Fatalf("got %v, want media.ErrNotFound", err)
			}
		})
	}
}

func TestDeleteIsIdempotent(t *testing.T) {
	s := newStore(t)
	ctx := context.Background()

	// Deleting a key that was never written must succeed.
	if err := s.Delete(ctx, "ghost"); err != nil {
		t.Fatalf("Delete of missing key: %v", err)
	}

	_, _ = s.Put(ctx, "real", strings.NewReader("x"))
	if err := s.Delete(ctx, "real"); err != nil {
		t.Fatalf("Delete: %v", err)
	}
	if _, err := s.Stat(ctx, "real"); !errors.Is(err, media.ErrNotFound) {
		t.Fatalf("after Delete, Stat got %v, want media.ErrNotFound", err)
	}
}

// TestTraversalIsContained proves a ".." key can never escape the root: it lands
// INSIDE root, and nothing appears as a sibling of root.
func TestTraversalIsContained(t *testing.T) {
	root := t.TempDir()
	s, _ := blob.NewFSStore(root)

	if _, err := s.Put(context.Background(), "../escape.txt", strings.NewReader("x")); err != nil {
		t.Fatalf("Put: %v", err)
	}
	// Must NOT exist next to root...
	if _, err := os.Stat(filepath.Join(root, "..", "escape.txt")); err == nil {
		t.Fatal("traversal escaped the storage root")
	}
	// ...it was contained inside root instead.
	if _, err := os.Stat(filepath.Join(root, "escape.txt")); err != nil {
		t.Fatalf("expected contained file inside root: %v", err)
	}
}

func TestMove(t *testing.T) {
	s := newStore(t)
	ctx := context.Background()

	// Missing source → domain sentinel.
	if err := s.Move(ctx, "nope", "dst"); !errors.Is(err, media.ErrNotFound) {
		t.Fatalf("Move of missing src: got %v, want media.ErrNotFound", err)
	}

	// Happy path: nested destination directories are created, source disappears.
	_, _ = s.Put(ctx, "staging/tmp", strings.NewReader("payload"))
	if err := s.Move(ctx, "staging/tmp", "ebooks/x/final.epub"); err != nil {
		t.Fatalf("Move: %v", err)
	}
	if _, err := s.Stat(ctx, "staging/tmp"); !errors.Is(err, media.ErrNotFound) {
		t.Fatalf("source survived Move: %v", err)
	}
	rc, err := s.Open(ctx, "ebooks/x/final.epub")
	if err != nil {
		t.Fatalf("Open destination: %v", err)
	}
	defer rc.Close()
	if got := readAll(t, rc); got != "payload" {
		t.Fatalf("destination content = %q, want %q", got, "payload")
	}
}

func TestDeleteDirectory(t *testing.T) {
	root := t.TempDir()
	s, _ := blob.NewFSStore(root)
	ctx := context.Background()

	_, _ = s.Put(ctx, "ebooks/42/file.epub", strings.NewReader("a"))
	_, _ = s.Put(ctx, "ebooks/42/cover.jpg", strings.NewReader("b"))
	_, _ = s.Put(ctx, "ebooks/99/other.epub", strings.NewReader("c"))

	if err := s.DeleteDirectory(ctx, "ebooks/42"); err != nil {
		t.Fatalf("DeleteDirectory: %v", err)
	}
	if _, err := s.Stat(ctx, "ebooks/42/file.epub"); !errors.Is(err, media.ErrNotFound) {
		t.Fatal("file under deleted directory still present")
	}
	if _, err := s.Stat(ctx, "ebooks/99/other.epub"); err != nil {
		t.Fatalf("unrelated file was removed: %v", err)
	}

	// Idempotent: deleting an absent directory is fine.
	if err := s.DeleteDirectory(ctx, "ebooks/does-not-exist"); err != nil {
		t.Fatalf("DeleteDirectory of missing prefix: %v", err)
	}
}

func TestDirectories(t *testing.T) {
	root := t.TempDir()
	s, _ := blob.NewFSStore(root)
	ctx := context.Background()

	_, _ = s.Put(ctx, "ebooks/42/file.epub", strings.NewReader("a"))
	_, _ = s.Put(ctx, "ebooks/99/file.epub", strings.NewReader("b"))
	_, _ = s.Put(ctx, "ebooks/loose.txt", strings.NewReader("c")) // a file, not a dir

	dirs, err := s.Directories(ctx, "ebooks")
	if err != nil {
		t.Fatalf("Directories: %v", err)
	}
	// Immediate child DIRECTORIES only — the loose file must not appear.
	got := map[string]bool{}
	for _, d := range dirs {
		got[d] = true
	}
	if !got["42"] || !got["99"] || got["loose.txt"] || len(dirs) != 2 {
		t.Fatalf("got %v, want exactly [42 99]", dirs)
	}

	// Empty prefix lists the root; missing prefix yields an empty slice.
	if _, err := s.Directories(ctx, ""); err != nil {
		t.Fatalf("Directories(root): %v", err)
	}
	if d, _ := s.Directories(ctx, "nope"); d != nil {
		t.Fatalf("missing prefix returned %v, want nil", d)
	}
}

func readAll(t *testing.T, r interface{ Read([]byte) (int, error) }) string {
	t.Helper()
	var sb strings.Builder
	buf := make([]byte, 32)
	for {
		n, err := r.Read(buf)
		sb.Write(buf[:n])
		if err != nil {
			break
		}
	}
	return sb.String()
}
