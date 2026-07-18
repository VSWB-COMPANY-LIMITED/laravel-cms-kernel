package mirror

import (
	"os"
	"path/filepath"
)

// WriteProbe performs a REAL write→read→delete against dir, using token (the
// run ID) so two concurrent runs don't collide on the same probe file. It
// exists because a permission-bit check (like os.Stat / is_writable()) can
// report "writable" on a disk that is actually wedged/read-only/dead — a
// disconnected external exFAT drive is the real-world case that motivated
// this (see GDriveMirrorSync::writeProbe() docblock). Any failure at any step
// collapses to false; this function never panics or returns an error, since
// it exists purely to answer "can I really write here right now?".
func WriteProbe(dir string, token string) bool {
	probe := filepath.Join(dir, ".gdrive_write_probe_"+token)
	defer os.Remove(probe)

	if err := os.WriteFile(probe, []byte("probe"), 0o644); err != nil {
		return false
	}
	data, err := os.ReadFile(probe)
	if err != nil {
		return false
	}
	return string(data) == "probe"
}
