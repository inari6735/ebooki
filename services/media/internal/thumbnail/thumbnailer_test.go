package thumbnail_test

import (
	"bytes"
	"image"
	"image/color"
	"image/png"
	"testing"

	_ "golang.org/x/image/webp" // decode WebP output to verify it

	"github.com/bookly/media/internal/media"
	"github.com/bookly/media/internal/thumbnail"
)

func pngSource(t *testing.T, w, h int) []byte {
	t.Helper()
	img := image.NewRGBA(image.Rect(0, 0, w, h))
	for y := 0; y < h; y++ {
		for x := 0; x < w; x++ {
			img.Set(x, y, color.RGBA{R: uint8(x), G: uint8(y), B: 64, A: 255})
		}
	}
	var buf bytes.Buffer
	if err := png.Encode(&buf, img); err != nil {
		t.Fatalf("encode png: %v", err)
	}
	return buf.Bytes()
}

// TestRenderCropsAndResizes: a 400x200 landscape source, asked for a 2:3 portrait
// 100px wide, must come out as a valid 100x150 WebP (crop-to-fill, not distorted).
func TestRenderCropsAndResizes(t *testing.T) {
	out, err := thumbnail.New().Render(
		pngSource(t, 400, 200), "webp", 80,
		[]media.ThumbnailSpec{{AspectW: 2, AspectH: 3, Width: 100}},
	)
	if err != nil {
		t.Fatalf("Render: %v", err)
	}
	if len(out) != 1 {
		t.Fatalf("got %d variants, want 1", len(out))
	}
	if out[0].Width != 100 || out[0].Height != 150 {
		t.Fatalf("reported dims %dx%d, want 100x150", out[0].Width, out[0].Height)
	}

	img, format, err := image.Decode(bytes.NewReader(out[0].Data))
	if err != nil {
		t.Fatalf("decode output: %v", err)
	}
	if format != "webp" {
		t.Fatalf("format = %q, want webp", format)
	}
	if img.Bounds().Dx() != 100 || img.Bounds().Dy() != 150 {
		t.Fatalf("decoded bounds = %v, want 100x150", img.Bounds())
	}
}

func TestRenderUnsupportedFormat(t *testing.T) {
	_, err := thumbnail.New().Render(
		pngSource(t, 10, 10), "gif", 80,
		[]media.ThumbnailSpec{{AspectW: 1, AspectH: 1, Width: 5}},
	)
	if err == nil {
		t.Fatal("want error for unsupported format")
	}
}

func TestRenderBadSource(t *testing.T) {
	_, err := thumbnail.New().Render(
		[]byte("not an image"), "webp", 80,
		[]media.ThumbnailSpec{{AspectW: 2, AspectH: 3, Width: 100}},
	)
	if err == nil {
		t.Fatal("want decode error for non-image source")
	}
}
