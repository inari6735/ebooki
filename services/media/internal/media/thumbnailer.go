package media

// ThumbnailSpec describes ONE variant to produce: the target aspect ratio
// (AspectW:AspectH) and the output Width. The service derives the height, so the
// caller only ever states a ratio and one side — the service has no domain
// knowledge of what the image is.
type ThumbnailSpec struct {
	AspectW uint32
	AspectH uint32
	Width   uint32
}

// Height is the output height derived from the aspect ratio and Width.
func (s ThumbnailSpec) Height() uint32 {
	if s.AspectW == 0 {
		return 0
	}
	return uint32(uint64(s.Width) * uint64(s.AspectH) / uint64(s.AspectW))
}

// RenderedThumbnail is one encoded variant produced by a Thumbnailer — pure bytes
// plus dimensions, no storage knowledge.
type RenderedThumbnail struct {
	Width  uint32
	Height uint32
	Data   []byte
}

// Thumbnailer decodes a source image ONCE and renders one encoded variant per
// spec. It is the port the core needs for image work; the codec/resizer lives in
// internal/thumbnail and satisfies this implicitly. Kept out of the core so the
// core imports no image libraries.
type Thumbnailer interface {
	Render(source []byte, format string, quality uint32, specs []ThumbnailSpec) ([]RenderedThumbnail, error)
}

// Variant is a stored thumbnail: its storage key and dimensions. Returned by the
// service so the caller can persist exactly what was written (e.g. into a
// media_thumbnails read table) without guessing keys.
type Variant struct {
	Key    string
	Width  uint32
	Height uint32
	Size   int64
}
