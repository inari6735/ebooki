// Package thumbnail implements media.Thumbnailer: decode → centered crop-to-fill
// to the target aspect ratio → high-quality resize → encode (WebP or JPEG). It is
// pure Go — WebP is encoded via an embedded libwebp WASM (gen2brain/webp) — so
// there is no cgo and no system image library to install.
package thumbnail

import (
	"bytes"
	"fmt"
	"image"
	"image/jpeg"
	_ "image/png" // register PNG decoder

	"github.com/gen2brain/webp"
	xdraw "golang.org/x/image/draw"
	_ "golang.org/x/image/webp" // register WebP decoder for webp sources

	"github.com/bookly/media/internal/media"
)

// Renderer is the concrete Thumbnailer. It holds no state.
type Renderer struct{}

func New() *Renderer { return &Renderer{} }

// Compile-time proof Renderer satisfies the core's port.
var _ media.Thumbnailer = (*Renderer)(nil)

func (Renderer) Render(source []byte, format string, quality uint32, specs []media.ThumbnailSpec) ([]media.RenderedThumbnail, error) {
	img, _, err := image.Decode(bytes.NewReader(source))
	if err != nil {
		return nil, fmt.Errorf("thumbnail: decode source: %w", err)
	}

	out := make([]media.RenderedThumbnail, 0, len(specs))
	for _, spec := range specs {
		if spec.Width == 0 || spec.AspectW == 0 || spec.AspectH == 0 {
			return nil, fmt.Errorf("thumbnail: invalid spec %+v", spec)
		}
		height := spec.Height()

		cropped := cropToAspect(img, int(spec.AspectW), int(spec.AspectH))

		dst := image.NewRGBA(image.Rect(0, 0, int(spec.Width), int(height)))
		xdraw.CatmullRom.Scale(dst, dst.Bounds(), cropped, cropped.Bounds(), xdraw.Over, nil)

		data, err := encode(dst, format, int(quality))
		if err != nil {
			return nil, err
		}
		out = append(out, media.RenderedThumbnail{Width: spec.Width, Height: height, Data: data})
	}
	return out, nil
}

// cropToAspect returns the largest centered sub-rectangle of img matching the
// aspect ratio aw:ah (crop-to-fill: we trim the overflowing side, never distort).
func cropToAspect(img image.Image, aw, ah int) image.Image {
	b := img.Bounds()
	sw, sh := b.Dx(), b.Dy()

	var cw, ch int
	if sw*ah > sh*aw { // source wider than target → limit by height
		ch = sh
		cw = sh * aw / ah
	} else { // source taller (or equal) → limit by width
		cw = sw
		ch = sw * ah / aw
	}

	x0 := b.Min.X + (sw-cw)/2
	y0 := b.Min.Y + (sh-ch)/2
	rect := image.Rect(x0, y0, x0+cw, y0+ch)

	// Most concrete image types expose SubImage — a zero-copy view of the crop.
	if si, ok := img.(interface {
		SubImage(image.Rectangle) image.Image
	}); ok {
		return si.SubImage(rect)
	}
	// Fallback: copy the crop region into a fresh image.
	dst := image.NewRGBA(image.Rect(0, 0, cw, ch))
	xdraw.Draw(dst, dst.Bounds(), img, rect.Min, xdraw.Src)
	return dst
}

func encode(img image.Image, format string, quality int) ([]byte, error) {
	var buf bytes.Buffer
	switch format {
	case "webp", "": // WebP is the default
		if err := webp.Encode(&buf, img, webp.Options{Quality: quality}); err != nil {
			return nil, fmt.Errorf("thumbnail: encode webp: %w", err)
		}
	case "jpeg", "jpg":
		if err := jpeg.Encode(&buf, img, &jpeg.Options{Quality: quality}); err != nil {
			return nil, fmt.Errorf("thumbnail: encode jpeg: %w", err)
		}
	default:
		return nil, fmt.Errorf("thumbnail: unsupported format %q", format)
	}
	return buf.Bytes(), nil
}
