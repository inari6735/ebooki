package main

import "os"

// config is the service's runtime configuration, sourced entirely from the
// environment — no config files, no flags. Twelve-factor style: the same binary
// runs anywhere, behaviour comes from the environment it's dropped into.
type config struct {
	// Addr is the TCP address the gRPC server listens on (host:port).
	Addr string
	// StorageRoot is the directory the filesystem blob store writes under. On
	// Upsun this points at the persistent mount owned by this app.
	StorageRoot string
}

func loadConfig() config {
	return config{
		// On Upsun the platform assigns the listen port via $PORT; locally we
		// default to :8090. An explicit MEDIA_ADDR overrides both.
		Addr:        env("MEDIA_ADDR", ":"+env("PORT", "8090")),
		StorageRoot: env("MEDIA_STORAGE_ROOT", "./var/media"),
	}
}

// env reads a variable, falling back to def when it is unset OR empty — an empty
// string in the environment is treated as "not provided", which is almost always
// what you want for config.
func env(key, def string) string {
	if v, ok := os.LookupEnv(key); ok && v != "" {
		return v
	}
	return def
}
