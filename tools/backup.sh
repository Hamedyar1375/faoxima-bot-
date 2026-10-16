#!/usr/bin/env bash
# Faoxima GitHub backup helper — session-based snapshots (run on the server).
#
# Usage:
#   tools/backup.sh snapshot "short description"   commit pending source changes + tag + push
#   tools/backup.sh rescue <tag>                   restore tracked files to a snapshot
#   tools/backup.sh list                           show recent snapshots
#
set -euo pipefail

REPO_DIR="/opt/faoxima"
cd "$REPO_DIR"

usage() {
    sed -n '2,8p' "$0" | sed 's/^# \{0,1\}//'
    exit 1
}

case "${1:-}" in
    snapshot)
        msg="${2:-manual snapshot}"
        if [ -x "$REPO_DIR/tools/parsecheck.sh" ]; then
            "$REPO_DIR/tools/parsecheck.sh" || { echo "❌ parsecheck failed — snapshot aborted"; exit 1; }
        fi
        git add -A
        if ! git diff --cached --quiet; then
            git commit -q -m "snapshot: ${msg} ($(date '+%Y-%m-%d %H:%M'))"
            echo "📦 committed: $(git log -1 --format='%h %s')"
        else
            echo "ℹ️  no pending source changes; tagging current HEAD"
        fi
        tag="pre-$(date +%Y%m%d-%H%M)"
        git tag -f "$tag" >/dev/null
        git push origin main --tags
        echo "✅ snapshot pushed: ${tag} → $(git log -1 --format='%h')"
        ;;
    rescue)
        tag="${2:?usage: tools/backup.sh rescue <tag>}"
        git fetch origin --tags -q
        git restore --source="$tag" --worktree -- .
        echo "✅ tracked files restored from ${tag}."
        echo "   runtime/untracked files were NOT touched. Review with 'git status' and commit or continue."
        ;;
    list)
        git fetch origin --tags -q 2>/dev/null || true
        echo "🏷  recent snapshots:"
        git tag -l 'pre-*' --sort=-creatordate | head -20
        ;;
    *)
        usage
        ;;
esac
