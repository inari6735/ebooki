// Module path is just a stable identifier — this service is a standalone binary,
// not a published library, so it never needs to resolve to a real URL. Keep it
// namespaced so imports read clearly (github.com/bookly/media/internal/media).
module github.com/bookly/media

go 1.25.0

require (
	github.com/gen2brain/webp v0.6.4
	golang.org/x/image v0.44.0
	google.golang.org/grpc v1.82.1
	google.golang.org/protobuf v1.36.11
)

require (
	github.com/ebitengine/purego v0.10.1 // indirect
	golang.org/x/net v0.53.0 // indirect
	golang.org/x/sys v0.43.0 // indirect
	golang.org/x/text v0.40.0 // indirect
	google.golang.org/genproto/googleapis/rpc v0.0.0-20260414002931-afd174a4e478 // indirect
)
