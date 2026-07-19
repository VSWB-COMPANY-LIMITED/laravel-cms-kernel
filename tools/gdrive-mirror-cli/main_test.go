package main

import (
	"reflect"
	"strings"
	"testing"

	"github.com/vswb/gdrive-mirror/internal/mirror"
)

func TestExtractFolderIDs_Single(t *testing.T) {
	ids, rest := extractFolderIDs([]string{"folder123", "--path=/tmp/x", "--force"})
	if !reflect.DeepEqual(ids, []string{"folder123"}) {
		t.Fatalf("ids = %v, want [folder123]", ids)
	}
	if !reflect.DeepEqual(rest, []string{"--path=/tmp/x", "--force"}) {
		t.Fatalf("rest = %v", rest)
	}
}

func TestExtractFolderIDs_Multiple(t *testing.T) {
	ids, rest := extractFolderIDs([]string{"folderA", "folderB", "folderC", "--path=/tmp/x"})
	if !reflect.DeepEqual(ids, []string{"folderA", "folderB", "folderC"}) {
		t.Fatalf("ids = %v, want [folderA folderB folderC]", ids)
	}
	if !reflect.DeepEqual(rest, []string{"--path=/tmp/x"}) {
		t.Fatalf("rest = %v", rest)
	}
}

// TestExtractFolderIDs_InterleavedWithFlags: folder IDs and flags can be
// mixed in any order (matches how Go's flag package would fail to parse
// them if handed directly — this pre-pass is what makes that possible).
func TestExtractFolderIDs_InterleavedWithFlags(t *testing.T) {
	ids, rest := extractFolderIDs([]string{"folderA", "--dry-run", "folderB", "--path=/tmp/x", "folderC"})
	if !reflect.DeepEqual(ids, []string{"folderA", "folderB", "folderC"}) {
		t.Fatalf("ids = %v, want [folderA folderB folderC] (order preserved)", ids)
	}
	if !reflect.DeepEqual(rest, []string{"--dry-run", "--path=/tmp/x"}) {
		t.Fatalf("rest = %v", rest)
	}
}

func TestExtractFolderIDs_None(t *testing.T) {
	ids, rest := extractFolderIDs([]string{"--retry-failed=report.json", "--path=/tmp/x"})
	if len(ids) != 0 {
		t.Fatalf("ids = %v, want empty", ids)
	}
	if !reflect.DeepEqual(rest, []string{"--retry-failed=report.json", "--path=/tmp/x"}) {
		t.Fatalf("rest = %v", rest)
	}
}

func TestExtractFolderIDs_Empty(t *testing.T) {
	ids, rest := extractFolderIDs(nil)
	if len(ids) != 0 || len(rest) != 0 {
		t.Fatalf("expected both empty, got ids=%v rest=%v", ids, rest)
	}
}

func TestAggregateExitCode_AllClean(t *testing.T) {
	results := []folderRunResult{
		{folderID: "a", stats: mirror.Stats{Updated: 5}},
		{folderID: "b", stats: mirror.Stats{Updated: 3}},
	}
	if got := aggregateExitCode(results); got != 0 {
		t.Fatalf("aggregateExitCode = %d, want 0", got)
	}
}

func TestAggregateExitCode_OneFolderHasErrors(t *testing.T) {
	results := []folderRunResult{
		{folderID: "a", stats: mirror.Stats{Updated: 5}},
		{folderID: "b", stats: mirror.Stats{Errors: 1}},
	}
	if got := aggregateExitCode(results); got != 1 {
		t.Fatalf("aggregateExitCode = %d, want 1", got)
	}
}

// TestAggregateExitCode_AbortedButZeroErrors covers the shrink-guard case:
// a folder that was aborted BEFORE downloading anything has Errors==0, but
// must still fail the run overall — silently exiting 0 would defeat the
// entire point of the guard.
func TestAggregateExitCode_AbortedButZeroErrors(t *testing.T) {
	results := []folderRunResult{
		{folderID: "a", stats: mirror.Stats{Aborted: true, AbortReason: "shrink-guard"}},
	}
	if got := aggregateExitCode(results); got != 1 {
		t.Fatalf("aggregateExitCode = %d, want 1 for an aborted folder", got)
	}
}

func TestAggregateExitCode_Empty(t *testing.T) {
	if got := aggregateExitCode(nil); got != 0 {
		t.Fatalf("aggregateExitCode(nil) = %d, want 0", got)
	}
}

func TestFormatMultiSummary_TotalsAcrossFolders(t *testing.T) {
	results := []folderRunResult{
		{folderID: "folderA", stats: mirror.Stats{Updated: 10, Skipped: 2, Errors: 0, Folders: 3}},
		{folderID: "folderB", stats: mirror.Stats{Updated: 5, Skipped: 1, Errors: 2, Folders: 1}},
	}
	out := formatMultiSummary(results)

	if !strings.Contains(out, "folderA") || !strings.Contains(out, "folderB") {
		t.Fatalf("expected both folder IDs in output:\n%s", out)
	}
	if !strings.Contains(out, "TỔNG (2 folder): updated=15 skipped=3 errors=2 folders=4") {
		t.Fatalf("expected totals line to sum both folders, got:\n%s", out)
	}
	if !strings.Contains(out, "CÓ LỖI") {
		t.Fatalf("expected folderB to be flagged as having errors, got:\n%s", out)
	}
}

func TestFormatMultiSummary_ShowsAbortReason(t *testing.T) {
	results := []folderRunResult{
		{folderID: "folderA", stats: mirror.Stats{Aborted: true, AbortReason: "shrink-guard"}},
	}
	out := formatMultiSummary(results)
	if !strings.Contains(out, "ABORTED (shrink-guard)") {
		t.Fatalf("expected abort reason in output, got:\n%s", out)
	}
}
