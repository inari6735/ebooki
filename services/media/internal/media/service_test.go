// Black-box test package (media_test, not media): we exercise ONLY the exported
// surface, exactly as a real caller would. If the tests need something the public
// API doesn't give, that's a design smell worth noticing.
package media_test

import (
	"bytes"
	"context"
	"errors"
	"io"
	"strings"
	"testing"

	"github.com/bookly/media/internal/media"
)

// fakeBlobstore is a tiny in-memory Blobstore. Because media.Blobstore is small
// and consumer-defined, we need no mocking library — a struct with the right
// methods satisfies the interface implicitly. `putErr` lets a test force a
// storage failure to check error wrapping.
type fakeBlobstore struct {
	data   map[string][]byte
	putErr error
}

func newFakeBlobstore() *fakeBlobstore {
	return &fakeBlobstore{data: map[string][]byte{}}
}

func (f *fakeBlobstore) Put(_ context.Context, key string, r io.Reader) (int64, error) {
	if f.putErr != nil {
		return 0, f.putErr
	}
	b, err := io.ReadAll(r)
	if err != nil {
		return 0, err
	}
	f.data[key] = b
	return int64(len(b)), nil
}

func (f *fakeBlobstore) Open(_ context.Context, key string) (io.ReadCloser, error) {
	b, ok := f.data[key]
	if !ok {
		return nil, media.ErrNotFound
	}
	return io.NopCloser(bytes.NewReader(b)), nil
}

func (f *fakeBlobstore) Stat(_ context.Context, key string) (media.Info, error) {
	b, ok := f.data[key]
	if !ok {
		return media.Info{}, media.ErrNotFound
	}
	return media.Info{Key: key, Size: int64(len(b))}, nil
}

func (f *fakeBlobstore) Delete(_ context.Context, key string) error {
	delete(f.data, key) // idempotent: deleting a missing key is fine
	return nil
}

func (f *fakeBlobstore) Move(_ context.Context, src, dst string) error {
	b, ok := f.data[src]
	if !ok {
		return media.ErrNotFound
	}
	f.data[dst] = b
	delete(f.data, src)
	return nil
}

func (f *fakeBlobstore) DeleteDirectory(_ context.Context, prefix string) error {
	for k := range f.data {
		if k == prefix || strings.HasPrefix(k, prefix+"/") {
			delete(f.data, k)
		}
	}
	return nil
}

func (f *fakeBlobstore) Directories(_ context.Context, _ string) ([]string, error) {
	return nil, nil // not exercised by the core tests
}

// TestStoreRejectsUnsafeKeys is the canonical table-driven test: one slice of
// cases, one loop, a subtest per case (t.Run) so failures name themselves. It
// checks the domain invariant BEFORE any storage call — so we pass a fake that
// would panic if touched, proving validation short-circuits.
func TestStoreRejectsUnsafeKeys(t *testing.T) {
	cases := []struct {
		name string
		key  string
	}{
		{"empty", ""},
		{"absolute", "/etc/passwd"},
		{"traversal", "covers/../../secret"},
	}

	svc := media.NewService(newFakeBlobstore())

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			_, err := svc.Store(context.Background(), tc.key, strings.NewReader("x"))
			if !errors.Is(err, media.ErrInvalidKey) {
				t.Fatalf("key %q: got err %v, want ErrInvalidKey", tc.key, err)
			}
		})
	}
}

// TestStoreThenReadRoundTrip checks the happy path end to end through the public
// API: store bytes, get correct size back, read them out again.
func TestStoreThenReadRoundTrip(t *testing.T) {
	svc := media.NewService(newFakeBlobstore())
	ctx := context.Background()
	const key, body = "covers/abc.jpg", "hello cover"

	info, err := svc.Store(ctx, key, strings.NewReader(body))
	if err != nil {
		t.Fatalf("Store: unexpected error: %v", err)
	}
	if info.Size != int64(len(body)) {
		t.Fatalf("Store: got size %d, want %d", info.Size, len(body))
	}

	rc, err := svc.Open(ctx, key)
	if err != nil {
		t.Fatalf("Open: unexpected error: %v", err)
	}
	defer rc.Close()

	got, _ := io.ReadAll(rc)
	if string(got) != body {
		t.Fatalf("Open: got %q, want %q", got, body)
	}
}

// TestOpenMissingKeyIsNotFound proves the sentinel error survives the service
// layer: the fake returns media.ErrNotFound, Service wraps it with context, and
// errors.Is still recognises it. This is why we wrap with %w, not %v.
func TestOpenMissingKeyIsNotFound(t *testing.T) {
	svc := media.NewService(newFakeBlobstore())

	_, err := svc.Open(context.Background(), "does/not/exist")
	if !errors.Is(err, media.ErrNotFound) {
		t.Fatalf("got err %v, want ErrNotFound", err)
	}
}

// TestStoreWrapsStorageError checks that a failure from the Blobstore is wrapped,
// not swallowed: the original cause stays reachable via errors.Is.
func TestStoreWrapsStorageError(t *testing.T) {
	boom := errors.New("disk full")
	fake := newFakeBlobstore()
	fake.putErr = boom

	svc := media.NewService(fake)

	_, err := svc.Store(context.Background(), "covers/x.jpg", strings.NewReader("x"))
	if !errors.Is(err, boom) {
		t.Fatalf("got err %v, want it to wrap %v", err, boom)
	}
}

// TestMoveValidatesBothEnds proves Move guards the source AND the destination
// key before touching storage — a bad key on either side is rejected.
func TestMoveValidatesBothEnds(t *testing.T) {
	cases := []struct {
		name     string
		src, dst string
	}{
		{"bad source", "../evil", "covers/ok.jpg"},
		{"bad destination", "covers/ok.jpg", ""},
	}
	svc := media.NewService(newFakeBlobstore())

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			if err := svc.Move(context.Background(), tc.src, tc.dst); !errors.Is(err, media.ErrInvalidKey) {
				t.Fatalf("got %v, want ErrInvalidKey", err)
			}
		})
	}
}

// TestMoveRelocatesBlob checks the happy path: a staged key becomes the final key.
func TestMoveRelocatesBlob(t *testing.T) {
	svc := media.NewService(newFakeBlobstore())
	ctx := context.Background()
	_, _ = svc.Store(ctx, "staging/tmp", strings.NewReader("payload"))

	if err := svc.Move(ctx, "staging/tmp", "ebooks/final.epub"); err != nil {
		t.Fatalf("Move: %v", err)
	}
	if _, err := svc.Stat(ctx, "staging/tmp"); !errors.Is(err, media.ErrNotFound) {
		t.Fatalf("source still present after Move: %v", err)
	}
	if _, err := svc.Stat(ctx, "ebooks/final.epub"); err != nil {
		t.Fatalf("destination missing after Move: %v", err)
	}
}

// TestDeleteDirectoryRefusesRoot is the safety net: an empty prefix must never
// reach storage, because it would wipe everything.
func TestDeleteDirectoryRefusesRoot(t *testing.T) {
	svc := media.NewService(newFakeBlobstore())

	if err := svc.DeleteDirectory(context.Background(), ""); !errors.Is(err, media.ErrInvalidKey) {
		t.Fatalf("got %v, want ErrInvalidKey for empty prefix", err)
	}
}

// TestDirectoriesAllowsRoot proves the asymmetry is intentional: listing (unlike
// deleting) accepts the empty/root prefix, which reconciliation relies on.
func TestDirectoriesAllowsRoot(t *testing.T) {
	svc := media.NewService(newFakeBlobstore())

	if _, err := svc.Directories(context.Background(), ""); err != nil {
		t.Fatalf("Directories(\"\"): unexpected error %v", err)
	}
}
