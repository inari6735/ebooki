// Module path is just a stable identifier — this service is a standalone binary,
// not a published library, so it never needs to resolve to a real URL. Keep it
// namespaced so imports read clearly (github.com/bookly/media/internal/media).
module github.com/bookly/media

go 1.26

require (
	google.golang.org/grpc v1.82.1
	google.golang.org/protobuf v1.36.11
)

require (
	golang.org/x/net v0.53.0 // indirect
	golang.org/x/sys v0.43.0 // indirect
	golang.org/x/text v0.36.0 // indirect
	google.golang.org/genproto/googleapis/rpc v0.0.0-20260414002931-afd174a4e478 // indirect
)
