#!/usr/bin/env bash
# This installer connects the My SEO For All project to its GitHub repository so the
# project can fetch remote references while preserving all existing local work. It
# validates that Git and the expected working-tree root are available, adds or
# verifies the configured remote, and fetches references with pruning enabled. It
# deliberately avoids reset, clean, merge, pull, checkout, and forced remote
# replacement operations unless the caller explicitly requests a remote URL change
# through GIT_REMOTE_FORCE=1. When the remote exposes a default branch, the script
# reports it and only creates a tracking branch when the local repository has no
# commit history; otherwise it leaves branch selection and synchronization to the
# project owner after reviewing the resulting state and diff.
set -Eeuo pipefail

REMOTE_URL="${GIT_REMOTE_URL:-git@github.com:tienlavan77/myseoforall.git}"
REMOTE_NAME="${GIT_REMOTE_NAME:-origin}"
SSH_KEY="${GIT_SSH_KEY:-$HOME/.ssh/id_ed25519_github}"
SSH_KEY="${SSH_KEY/#\~/$HOME}"
PROJECT_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"

fail() {
  printf 'gitinstall: error: %s\n' "$1" >&2
  exit 1
}

command -v git >/dev/null 2>&1 || fail "Git is not installed or is not on PATH."
[ -f "$SSH_KEY" ] || fail "SSH private key not found: $SSH_KEY (do not use the .pub file)."
[ ! -f "${SSH_KEY}.pub" ] || true
git -C "$PROJECT_ROOT" rev-parse --show-toplevel >/dev/null 2>&1 || fail "Project is not a Git working tree: $PROJECT_ROOT"

export GIT_SSH_COMMAND="ssh -i $SSH_KEY -o IdentitiesOnly=yes"

ACTUAL_ROOT="$(git -C "$PROJECT_ROOT" rev-parse --show-toplevel)"
[ "$ACTUAL_ROOT" = "$PROJECT_ROOT" ] || fail "Unexpected Git root: $ACTUAL_ROOT"

if git -C "$PROJECT_ROOT" remote get-url "$REMOTE_NAME" >/dev/null 2>&1; then
  CURRENT_URL="$(git -C "$PROJECT_ROOT" remote get-url "$REMOTE_NAME")"
  if [ "$CURRENT_URL" != "$REMOTE_URL" ]; then
    if [ "${GIT_REMOTE_FORCE:-0}" = "1" ]; then
      git -C "$PROJECT_ROOT" remote set-url "$REMOTE_NAME" "$REMOTE_URL"
      printf 'gitinstall: changed %s remote to %s\n' "$REMOTE_NAME" "$REMOTE_URL"
    else
      fail "Remote $REMOTE_NAME already points to $CURRENT_URL. Set GIT_REMOTE_FORCE=1 to replace it."
    fi
  else
    printf 'gitinstall: remote %s is already configured.\n' "$REMOTE_NAME"
  fi
else
  git -C "$PROJECT_ROOT" remote add "$REMOTE_NAME" "$REMOTE_URL"
  printf 'gitinstall: added remote %s -> %s\n' "$REMOTE_NAME" "$REMOTE_URL"
fi

printf 'gitinstall: fetching remote references (no files will be changed)...\n'
git -C "$PROJECT_ROOT" fetch --prune "$REMOTE_NAME"

REMOTE_HEAD="$(git -C "$PROJECT_ROOT" symbolic-ref --quiet --short "refs/remotes/$REMOTE_NAME/HEAD" 2>/dev/null || true)"
if [ -n "$REMOTE_HEAD" ]; then
  DEFAULT_BRANCH="${REMOTE_HEAD#"$REMOTE_NAME/"}"
else
  DEFAULT_BRANCH="$(git -C "$PROJECT_ROOT" for-each-ref --format='%(refname:short)' "refs/remotes/$REMOTE_NAME" | sed -n 's#^'"$REMOTE_NAME"'/##p' | grep -E '^(main|master)$' | head -n 1 || true)"
fi

if [ -n "$DEFAULT_BRANCH" ]; then
  printf 'gitinstall: remote default branch: %s\n' "$DEFAULT_BRANCH"
  if git -C "$PROJECT_ROOT" show-ref --verify --quiet "refs/heads/$DEFAULT_BRANCH"; then
    LOCAL_BRANCH="$(git -C "$PROJECT_ROOT" branch --show-current)"
    printf 'gitinstall: keeping current local branch: %s\n' "${LOCAL_BRANCH:-detached HEAD}"
  elif [ -z "$(git -C "$PROJECT_ROOT" rev-parse --verify HEAD 2>/dev/null || true)" ]; then
    git -C "$PROJECT_ROOT" switch --create "$DEFAULT_BRANCH" --track "$REMOTE_NAME/$DEFAULT_BRANCH"
    printf 'gitinstall: created local branch %s tracking %s/%s\n' "$DEFAULT_BRANCH" "$REMOTE_NAME" "$DEFAULT_BRANCH"
  else
    printf 'gitinstall: local history exists; no branch was created or switched.\n'
    printf 'gitinstall: inspect with: git diff %s/%s\n' "$REMOTE_NAME" "$DEFAULT_BRANCH"
  fi
else
  printf 'gitinstall: warning: could not determine a remote default branch.\n' >&2
fi

printf '\ngitinstall: Git remote setup completed without destructive synchronization.\n'
git -C "$PROJECT_ROOT" remote -v
git -C "$PROJECT_ROOT" status --short
printf '\nTo synchronize later, review the diff first and use an explicit command such as:\n'
printf '  git -C %q pull --ff-only %s %s\n' "$PROJECT_ROOT" "$REMOTE_NAME" "${DEFAULT_BRANCH:-<branch>}"
