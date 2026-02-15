#!/bin/bash
#
# markdown-alternate GitHub Issue Processing Script
# Fetches open issues from GitHub, processes them with Claude Code (2-phase: plan then implement),
# creates PRs, requests Copilot review, and assigns PRs to jdevalk.
#
# Usage:
#   bin/process-issues.sh                    # Show oldest open issue (dry run)
#   bin/process-issues.sh --run              # Process one issue with Claude Code
#   bin/process-issues.sh --loop             # Process all issues, review PRs
#   bin/process-issues.sh --loop --optimize  # Process all issues, review PRs, then optimize
#   bin/process-issues.sh --issue=N          # Target a specific issue
#   bin/process-issues.sh --json             # Output raw JSON
#   bin/process-issues.sh --help             # Show help
#

# Wrap entire script in a block so bash reads the whole file before executing.
# This prevents corruption if git pull updates this script while it's running.
{

# Don't use set -e - we want to handle errors ourselves and log them

# ============================================================================
# Setup: constants and paths
# ============================================================================

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

GITHUB_REPO="ProgressPlanner/markdown-alternate"
GITHUB_USER="jdevalk"

# Lock file to prevent concurrent runs
LOCK_FILE="/tmp/markdown-alternate-issues.lock"
LOCK_CREATED=false
SCRIPT_COMPLETED=false

# Track current issue for crash cleanup
CURRENT_ISSUE_NUMBER=""

# ============================================================================
# Lock file management
# ============================================================================

check_lock() {
    if [ -f "$LOCK_FILE" ]; then
        local pid=$(cat "$LOCK_FILE" 2>/dev/null)
        if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then
            log "INFO" "Another session already running (PID: $pid), exiting"
            echo "Another session is already running (PID: $pid)" >&2
            echo "Lock file: $LOCK_FILE" >&2
            exit 0
        else
            log "INFO" "Removing stale lock file"
            rm -f "$LOCK_FILE"
        fi
    fi
}

create_lock() {
    echo $$ > "$LOCK_FILE"
    LOCK_CREATED=true
}

# ============================================================================
# Signal handling and cleanup
# ============================================================================

cleanup() {
    local exit_code=$?
    if [ "$LOCK_CREATED" = true ]; then
        if [ "$SCRIPT_COMPLETED" = false ]; then
            local timestamp=$(date '+%Y-%m-%d %H:%M:%S')
            echo "[$timestamp] [ERROR] Script exited unexpectedly (exit code: $exit_code, signal: $TRAPPED_SIGNAL)" >> "$LOG_FILE"

            # Remove in-progress label if we crashed mid-processing
            if [ -n "$CURRENT_ISSUE_NUMBER" ]; then
                echo "[$timestamp] [ERROR] Removing in-progress label from issue #${CURRENT_ISSUE_NUMBER}" >> "$LOG_FILE"
                remove_label "$CURRENT_ISSUE_NUMBER" "in-progress" 2>/dev/null
            fi
        fi

        # Always return to main and clean up branches
        cd "$PROJECT_ROOT" 2>/dev/null
        git checkout main 2>/dev/null
        git branch --merged main | grep -E '^\s+(issue|optimize)/' | xargs -r git branch -d 2>/dev/null

        rm -f "$LOCK_FILE"
    fi
}

handle_signal() {
    TRAPPED_SIGNAL="$1"
    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    echo "[$timestamp] [ERROR] Script received signal: $TRAPPED_SIGNAL" >> "$LOG_FILE"
    exit 1
}

TRAPPED_SIGNAL=""
trap cleanup EXIT
trap 'handle_signal SIGTERM' SIGTERM
trap 'handle_signal SIGINT' SIGINT
trap 'handle_signal SIGHUP' SIGHUP

# ============================================================================
# Colors and logging
# ============================================================================

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

LOG_FILE="$PROJECT_ROOT/logs/process-issues.log"
mkdir -p "$(dirname "$LOG_FILE")"

log() {
    local level="$1"
    shift
    local message="$*"
    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    echo "[$timestamp] [$level] $message" >> "$LOG_FILE"
}

log "INFO" "=== Script started (PID: $$) ==="

# ============================================================================
# Argument parsing
# ============================================================================

ISSUE_NUMBER=""
OUTPUT_FORMAT="claude"
RUN_CLAUDE=false
LOOP_MODE=false
OPTIMIZE_MODE=false

while [[ $# -gt 0 ]]; do
    case $1 in
        --issue=*)
            ISSUE_NUMBER="${1#*=}"
            shift
            ;;
        --run)
            RUN_CLAUDE=true
            shift
            ;;
        --loop)
            LOOP_MODE=true
            RUN_CLAUDE=true
            shift
            ;;
        --optimize)
            OPTIMIZE_MODE=true
            shift
            ;;
        --json)
            OUTPUT_FORMAT="json"
            shift
            ;;
        --launchd-install)
            PLIST_LABEL="com.markdown-alternate.process-issues"
            PLIST_PATH="$HOME/Library/LaunchAgents/${PLIST_LABEL}.plist"
            SCRIPT_PATH="$SCRIPT_DIR/process-issues.sh"

            # Unload existing if present
            if launchctl list "$PLIST_LABEL" &>/dev/null; then
                echo -e "${YELLOW}Unloading existing launchd job...${NC}"
                launchctl unload "$PLIST_PATH" 2>/dev/null
            fi

            mkdir -p "$PROJECT_ROOT/logs"

            cat > "$PLIST_PATH" <<PLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key>
    <string>${PLIST_LABEL}</string>
    <key>ProgramArguments</key>
    <array>
        <string>${SCRIPT_PATH}</string>
        <string>--loop</string>
        <string>--optimize</string>
    </array>
    <key>WorkingDirectory</key>
    <string>${PROJECT_ROOT}</string>
    <key>StartInterval</key>
    <integer>3600</integer>
    <key>StandardOutPath</key>
    <string>${PROJECT_ROOT}/logs/launchd-stdout.log</string>
    <key>StandardErrorPath</key>
    <string>${PROJECT_ROOT}/logs/launchd-stderr.log</string>
    <key>RunAtLoad</key>
    <false/>
</dict>
</plist>
PLIST

            # Ensure .env exists with launchd-required vars
            if [ ! -f "$PROJECT_ROOT/.env" ]; then
                HOMEBREW_BIN=$(command -v brew 2>/dev/null && brew --prefix 2>/dev/null || echo "/opt/homebrew")
                cat > "$PROJECT_ROOT/.env" <<ENVFILE
HOMEBREW_PATH=${HOMEBREW_BIN}/bin
USER_HOME=${HOME}
ENVFILE
                echo -e "${GREEN}Created .env with HOMEBREW_PATH and USER_HOME${NC}"
            fi

            launchctl load "$PLIST_PATH"
            echo -e "${GREEN}Installed and loaded launchd job:${NC}"
            echo "  Plist: $PLIST_PATH"
            echo "  Runs:  every hour (--loop --optimize)"
            echo "  Logs:  $PROJECT_ROOT/logs/"
            echo ""
            echo "Commands:"
            echo "  launchctl start $PLIST_LABEL    # Run now"
            echo "  launchctl unload $PLIST_PATH    # Disable"
            echo "  launchctl load $PLIST_PATH      # Re-enable"
            exit 0
            ;;
        --launchd-uninstall)
            PLIST_LABEL="com.markdown-alternate.process-issues"
            PLIST_PATH="$HOME/Library/LaunchAgents/${PLIST_LABEL}.plist"

            if [ ! -f "$PLIST_PATH" ]; then
                echo -e "${RED}No launchd job found at $PLIST_PATH${NC}" >&2
                exit 1
            fi

            launchctl unload "$PLIST_PATH" 2>/dev/null
            rm -f "$PLIST_PATH"
            echo -e "${GREEN}Uninstalled launchd job and removed plist.${NC}"
            exit 0
            ;;
        --help|-h)
            echo "markdown-alternate GitHub Issue Processing Script"
            echo ""
            echo "Fetches open issues and processes them with Claude Code."
            echo "Creates PRs for resolved items, posts follow-up questions when needed."
            echo ""
            echo "Usage: bin/process-issues.sh [OPTIONS]"
            echo ""
            echo "Options:"
            echo "  --run                Process one issue with Claude Code"
            echo "  --loop               Process all open issues one by one (implies --run)"
            echo "  --optimize           When no issues remain, review PHP files for optimization PRs"
            echo "  --issue=N            Target a specific issue by number"
            echo "  --json               Output raw JSON from GitHub"
            echo "  --launchd-install    Install as macOS launchd job (runs every 30 min)"
            echo "  --launchd-uninstall  Remove the launchd job"
            echo "  --help, -h           Show this help message"
            echo ""
            echo "Examples:"
            echo "  bin/process-issues.sh                       # Show oldest open issue (dry run)"
            echo "  bin/process-issues.sh --run                 # Process one issue"
            echo "  bin/process-issues.sh --run --issue=5       # Process issue #5"
            echo "  bin/process-issues.sh --loop --optimize     # Process all, review PRs, then optimize"
            echo "  bin/process-issues.sh --json                # Show raw JSON"
            echo "  bin/process-issues.sh --launchd-install     # Install as scheduled job"
            exit 0
            ;;
        *)
            echo -e "${RED}Unknown option: $1${NC}" >&2
            echo "Use --help for usage information." >&2
            exit 1
            ;;
    esac
done

# ============================================================================
# Environment loading (for cron/launchd)
# ============================================================================

ENV_FILE="$PROJECT_ROOT/.env"
if [ -f "$ENV_FILE" ]; then
    set -a
    source "$ENV_FILE"
    set +a
fi

if [ -n "$HOMEBREW_PATH" ]; then
    export PATH="$HOMEBREW_PATH:$PATH"
fi

if [ -n "$USER_HOME" ]; then
    export HOME="$USER_HOME"
fi

# ============================================================================
# Dependency checks
# ============================================================================

if ! command -v gh &> /dev/null; then
    echo -e "${RED}Error: gh (GitHub CLI) is required but not installed${NC}" >&2
    echo "Install with: brew install gh" >&2
    exit 1
fi

if ! gh auth status &> /dev/null; then
    echo -e "${RED}Error: gh is not authenticated${NC}" >&2
    echo "Run: gh auth login" >&2
    exit 1
fi

if ! command -v jq &> /dev/null; then
    echo -e "${RED}Error: jq is required but not installed${NC}" >&2
    echo "Install with: brew install jq" >&2
    exit 1
fi

# ============================================================================
# Lock and git setup (only when running Claude)
# ============================================================================

if [ "$RUN_CLAUDE" = true ]; then
    check_lock
    create_lock

    cd "$PROJECT_ROOT"
    git reset --hard HEAD 2>/dev/null
    git checkout main 2>/dev/null
    git pull --ff-only 2>/dev/null
fi

# ============================================================================
# ensure_labels_exist — creates in-progress/needs-info labels if missing
# ============================================================================

ensure_labels_exist() {
    local labels=("approved:0e8a16:Issue approved for automated processing" "in-progress:0e8a16:Issue is being processed by automation" "needs-info:d876e3:Waiting for more information")

    for entry in "${labels[@]}"; do
        local name="${entry%%:*}"
        local rest="${entry#*:}"
        local color="${rest%%:*}"
        local description="${rest#*:}"

        if ! gh label list --repo "$GITHUB_REPO" --json name -q ".[].name" 2>/dev/null | grep -qx "$name"; then
            log "INFO" "Creating label: $name"
            gh label create "$name" --repo "$GITHUB_REPO" --color "$color" --description "$description" 2>/dev/null || true
        fi
    done
}

if [ "$RUN_CLAUDE" = true ]; then
    ensure_labels_exist
fi

# ============================================================================
# ensure_clean_main — verify clean working directory and checkout main
# ============================================================================

ensure_clean_main() {
    if [ -n "$(git status --porcelain)" ]; then
        log "ERROR" "Working directory is dirty in $(pwd), aborting"
        echo -e "${RED}Error: Working directory is dirty. Commit or stash changes first.${NC}" >&2
        return 1
    fi

    git checkout main 2>/dev/null
    git pull --ff-only 2>/dev/null

    return 0
}

# ============================================================================
# run_claude — execute Claude with timeout
# Usage: run_claude <prompt_file> <output_file> [timeout_seconds] [model] [extra_flags]
# Sets CLAUDE_EXIT and CLAUDE_OUTPUT globals
# ============================================================================

run_claude() {
    local prompt_file="$1"
    local output_file="$2"
    local timeout_secs="${3:-600}"
    local model="${4:-}"
    local extra_flags="${5:-}"

    CLAUDE_BIN="${CLAUDE_PATH:-claude}"

    local model_args=()
    if [ -n "$model" ]; then
        model_args=(--model "$model")
        log "INFO" "Using model: $model"
    fi

    local extra_args=()
    if [ -n "$extra_flags" ]; then
        read -ra extra_args <<< "$extra_flags"
        log "INFO" "Extra flags: $extra_flags"
    fi

    "$CLAUDE_BIN" --print --dangerously-skip-permissions "${model_args[@]}" "${extra_args[@]}" < "$prompt_file" > "$output_file" 2>&1 &
    local claude_pid=$!
    local elapsed=0

    while kill -0 "$claude_pid" 2>/dev/null; do
        sleep 10
        elapsed=$((elapsed + 10))
        if [ "$elapsed" -ge "$timeout_secs" ]; then
            log "ERROR" "Claude session timed out after ${timeout_secs}s (PID: $claude_pid) — killing"
            kill "$claude_pid" 2>/dev/null
            wait "$claude_pid" 2>/dev/null
            git reset --hard HEAD 2>/dev/null
            git checkout main 2>/dev/null
            CLAUDE_EXIT=124
            CLAUDE_OUTPUT=$(cat "$output_file" 2>/dev/null)
            if [ -n "$CLAUDE_OUTPUT" ]; then
                local timeout_snippet=$(echo "$CLAUDE_OUTPUT" | tail -20)
                log "ERROR" "Claude output at timeout: $timeout_snippet"
            fi
            rm -f "$prompt_file" "$output_file"
            return 124
        fi
        if [ $((elapsed % 120)) -eq 0 ]; then
            log "INFO" "Claude session still running (${elapsed}s elapsed, PID: $claude_pid)"
        fi
    done

    wait "$claude_pid"
    CLAUDE_EXIT=$?
    CLAUDE_OUTPUT=$(cat "$output_file" 2>/dev/null)
    rm -f "$prompt_file" "$output_file"
    log "INFO" "Claude session finished in ${elapsed}s (exit: $CLAUDE_EXIT)"

    if [ $CLAUDE_EXIT -ne 0 ] && [ -n "$CLAUDE_OUTPUT" ]; then
        local error_snippet=$(echo "$CLAUDE_OUTPUT" | head -20)
        log "ERROR" "Claude output on failure: $error_snippet"
    fi

    return $CLAUDE_EXIT
}

# ============================================================================
# GitHub helpers
# ============================================================================

add_label() {
    local issue_number="$1"
    local label="$2"
    gh issue edit "$issue_number" --repo "$GITHUB_REPO" --add-label "$label" 2>/dev/null
}

remove_label() {
    local issue_number="$1"
    local label="$2"
    gh issue edit "$issue_number" --repo "$GITHUB_REPO" --remove-label "$label" 2>/dev/null
}

post_issue_comment() {
    local issue_number="$1"
    local body="$2"
    gh issue comment "$issue_number" --repo "$GITHUB_REPO" --body "$body" 2>/dev/null
}

# ============================================================================
# format_issue_for_claude — format issue JSON as markdown prompt
# ============================================================================

format_issue_for_claude() {
    local json="$1"

    local number=$(echo "$json" | jq -r '.number')
    local title=$(echo "$json" | jq -r '.title')
    local body=$(echo "$json" | jq -r '.body // ""')
    local author=$(echo "$json" | jq -r '.author.login // "unknown"')
    local created=$(echo "$json" | jq -r '.createdAt')
    local labels=$(echo "$json" | jq -r '[.labels[].name] | join(", ")')
    local comment_count=$(echo "$json" | jq -r '.comments | length')

    echo "# Issue #${number}: ${title}"
    echo ""
    echo "**Author:** ${author} | **Created:** ${created} | **Labels:** ${labels:-none}"
    echo ""

    echo "## Description"
    echo "$body"
    echo ""

    # Include comments if any
    if [ "$comment_count" -gt 0 ] 2>/dev/null; then
        echo "## Comments"
        echo ""
        echo "$json" | jq -r '.comments[] | "**\(.author.login)** (\(.createdAt)):\n\(.body)\n"'
        echo ""
    fi

    # Append agent instructions
    local agent_prompt="$PROJECT_ROOT/.claude/agent-prompt.md"
    if [ -f "$agent_prompt" ]; then
        echo "---"
        echo ""
        cat "$agent_prompt"
    fi
}

# ============================================================================
# parse_claude_response — extract STATUS, PR_URL, QUESTION
# ============================================================================

parse_claude_response() {
    local response="$1"

    if echo "$response" | grep -qi "STATUS:.*IN_REVIEW"; then
        PARSED_STATUS="in_review"
    elif echo "$response" | grep -qi "STATUS:.*RESOLVED"; then
        PARSED_STATUS="resolved"
    elif echo "$response" | grep -qi "STATUS:.*NEEDS_INFO"; then
        PARSED_STATUS="needs_info"
    elif echo "$response" | grep -qi "STATUS:.*DECLINED"; then
        PARSED_STATUS="declined"
    elif echo "$response" | grep -qi "STATUS:.*FIXED"; then
        PARSED_STATUS="fixed"
    elif echo "$response" | grep -qi "STATUS:.*NO_CHANGES"; then
        PARSED_STATUS="no_changes"
    else
        PARSED_STATUS=""
    fi

    PARSED_PR_URL=$(echo "$response" | grep -i "PR_URL:" | tail -1 | sed 's/.*PR_URL:\s*//' | tr -d '[:space:]')
    PARSED_QUESTION=$(echo "$response" | grep -i "QUESTION:" | tail -1 | sed 's/.*QUESTION:\s*//')
}

# ============================================================================
# process_issue — two-phase processing with Claude
# ============================================================================

process_issue() {
    local issue_json="$1"

    CURRENT_ISSUE_NUMBER=$(echo "$issue_json" | jq -r '.number')
    local title=$(echo "$issue_json" | jq -r '.title')

    log "INFO" "Processing issue #${CURRENT_ISSUE_NUMBER}: \"${title}\""
    echo -e "${GREEN}Processing issue #${CURRENT_ISSUE_NUMBER}: ${title}${NC}" >&2

    cd "$PROJECT_ROOT"
    if ! ensure_clean_main; then
        CURRENT_ISSUE_NUMBER=""
        return 1
    fi

    # Add in-progress label to prevent re-pickup
    add_label "$CURRENT_ISSUE_NUMBER" "in-progress"

    # Format the prompt
    local output=$(format_issue_for_claude "$issue_json")

    # --- Phase 1: Planning with Sonnet ---
    log "INFO" "Starting planning session for issue #${CURRENT_ISSUE_NUMBER}"
    echo -e "${YELLOW}Phase 1/2: Planning with Sonnet...${NC}" >&2

    local plan_prompt="${output}

---

## YOUR TASK: Create an Implementation Plan

You are in PLANNING MODE. Do NOT make any code changes, do NOT create branches, do NOT commit anything.

### Step 1: Decide if you have enough information

**Before planning anything**, check if the issue is clear enough to act on. Most issues ARE clear enough — prefer action over asking questions.

Only ask for clarification (STATUS: NEEDS_INFO) when you TRULY cannot proceed:
- The description is so vague you cannot identify what to change at all
- There are multiple contradictory interpretations and picking wrong would cause harm
- The requested feature requires design decisions that only the user can make

**Do NOT ask about:**
- Edge cases you can handle with reasonable defaults
- Implementation details you can decide yourself
- Things that are obvious from the codebase

**Err on the side of acting.** Make reasonable choices, document them in your PR description, and let the reviewer adjust if needed.

If clarification is truly needed, output ONLY this (no plan):
STATUS: NEEDS_INFO
QUESTION: Your specific question — be concrete about what you need to know

If the issue should be declined (out of scope, not feasible, already works as designed), output ONLY:
STATUS: DECLINED

### Step 2: Create the plan

If you are confident you understand exactly what needs to change, produce a plan:

1. **Files to modify** — List every file that needs changes, with the specific changes needed
2. **New files** (if any) — What new files need to be created and what they should contain
3. **Implementation steps** — Numbered step-by-step instructions specific enough for another developer to follow
4. **Testing** — How to verify the changes work (build commands, what to check)
5. **PR details** — Suggested branch name, PR title, and PR description

Be specific about code changes — include function names, class names, and describe the logic. Do NOT include actual code blocks, just describe what needs to change."

    local prompt_file=$(mktemp)
    local output_file=$(mktemp)
    printf '%s' "$plan_prompt" > "$prompt_file"

    run_claude "$prompt_file" "$output_file" 600 "sonnet"

    local plan_output="$CLAUDE_OUTPUT"
    local plan_exit=$CLAUDE_EXIT

    if [ $plan_exit -ne 0 ]; then
        log "ERROR" "Planning session failed (exit code: $plan_exit)"
        echo -e "${RED}Planning session failed (exit code: $plan_exit)${NC}" >&2
        remove_label "$CURRENT_ISSUE_NUMBER" "in-progress"
        cd "$PROJECT_ROOT" && git checkout main 2>/dev/null
        CURRENT_ISSUE_NUMBER=""
        return 1
    fi

    log "INFO" "Planning session completed"

    # Check if the plan indicates NEEDS_INFO or DECLINED — skip implementation
    if echo "$plan_output" | grep -qi "STATUS:.*NEEDS_INFO\|STATUS:.*DECLINED"; then
        log "INFO" "Planning phase returned early status — skipping implementation"
        echo "$plan_output"
        CLAUDE_OUTPUT="$plan_output"
        CLAUDE_EXIT=0
    else
        # --- Phase 2: Implementation with Sonnet ---
        log "INFO" "Starting implementation session for issue #${CURRENT_ISSUE_NUMBER}"
        echo -e "${YELLOW}Phase 2/2: Implementing with Sonnet...${NC}" >&2

        local impl_prompt="${output}

---

## Implementation Plan (from planning phase)

${plan_output}

---

## YOUR TASK: Execute the Plan

Follow the implementation plan above. The plan was created by a senior engineer who analyzed the issue and codebase. Execute it step by step:

1. Create the branch as specified in the plan
2. Make all the code changes described
3. Run \`composer install\` to verify dependencies
4. Commit and push
5. Create the PR as described in the plan (body MUST include \`Closes #${CURRENT_ISSUE_NUMBER}\`)
6. Output your status (STATUS: IN_REVIEW with PR_URL, or STATUS: NEEDS_INFO/DECLINED if you hit a blocker)"

        prompt_file=$(mktemp)
        output_file=$(mktemp)
        printf '%s' "$impl_prompt" > "$prompt_file"

        run_claude "$prompt_file" "$output_file" 600 "sonnet"

        echo "$CLAUDE_OUTPUT"
    fi

    if [ $CLAUDE_EXIT -ne 0 ]; then
        log "ERROR" "Claude session failed (exit code: $CLAUDE_EXIT)"
        echo -e "${RED}Claude session failed (exit code: $CLAUDE_EXIT)${NC}" >&2
        remove_label "$CURRENT_ISSUE_NUMBER" "in-progress"
        cd "$PROJECT_ROOT" && git checkout main 2>/dev/null
        CURRENT_ISSUE_NUMBER=""
        return 1
    fi

    log "INFO" "Claude session completed successfully"

    # Parse response
    parse_claude_response "$CLAUDE_OUTPUT"

    case "$PARSED_STATUS" in
        in_review|resolved)
            remove_label "$CURRENT_ISSUE_NUMBER" "in-progress"
            if [ -n "$PARSED_PR_URL" ]; then
                log "INFO" "Issue #${CURRENT_ISSUE_NUMBER} — PR created: ${PARSED_PR_URL}"
                # Request Copilot code review and assign to jdevalk
                local pr_number=$(echo "$PARSED_PR_URL" | grep -oE '[0-9]+$')
                if [ -n "$pr_number" ]; then
                    log "INFO" "Requesting Copilot review for PR #${pr_number}"
                    gh api -X POST "repos/${GITHUB_REPO}/pulls/${pr_number}/requested_reviewers" \
                        --input - <<< '{"reviewers":["copilot-pull-request-reviewer[bot]"]}' > /dev/null 2>&1 \
                        || log "WARN" "Copilot review request failed for PR #${pr_number}"

                    log "INFO" "Assigning PR #${pr_number} to ${GITHUB_USER}"
                    gh pr edit "$pr_number" --repo "$GITHUB_REPO" --add-assignee "$GITHUB_USER" 2>/dev/null \
                        || log "WARN" "Failed to assign PR #${pr_number} to ${GITHUB_USER}"
                fi
            fi
            ;;
        needs_info)
            remove_label "$CURRENT_ISSUE_NUMBER" "in-progress"
            add_label "$CURRENT_ISSUE_NUMBER" "needs-info"
            if [ -n "$PARSED_QUESTION" ]; then
                post_issue_comment "$CURRENT_ISSUE_NUMBER" "$PARSED_QUESTION"
                log "INFO" "Issue #${CURRENT_ISSUE_NUMBER} needs info: \"${PARSED_QUESTION}\""
            fi
            ;;
        declined)
            remove_label "$CURRENT_ISSUE_NUMBER" "in-progress"
            gh issue close "$CURRENT_ISSUE_NUMBER" --repo "$GITHUB_REPO" 2>/dev/null
            log "INFO" "Issue #${CURRENT_ISSUE_NUMBER} declined and closed"
            ;;
        *)
            # No status parsed — remove in-progress, leave open for next run
            remove_label "$CURRENT_ISSUE_NUMBER" "in-progress"
            log "INFO" "No final status for issue #${CURRENT_ISSUE_NUMBER} — remains in queue"
            echo -e "${YELLOW}No final status. Issue remains in queue for next run.${NC}" >&2
            ;;
    esac

    # Return to main and clean up
    cd "$PROJECT_ROOT"
    git checkout main 2>/dev/null
    git branch --merged main | grep -E '^\s+(issue|optimize)/' | xargs -r git branch -d 2>/dev/null

    CURRENT_ISSUE_NUMBER=""
}

# ============================================================================
# format_review_prompt — format Copilot review comments for Claude
# ============================================================================

format_review_prompt() {
    local pr_number="$1"
    local branch="$2"
    local copilot_comments="$3"

    local prompt="# PR Review Fix: PR #${pr_number}

**Branch:** \`${branch}\`

You are on the \`${branch}\` branch. Below are Copilot's inline review comments on this PR.
Evaluate each comment and fix what's worth fixing.

## Copilot Review Comments

"

    prompt+=$(echo "$copilot_comments" | jq -r '.[] | "### \(.path) (line \(.line // .original_line // "N/A"))\n\(.body)\n"')

    local review_prompt_file="$PROJECT_ROOT/.claude/review-fix-prompt.md"
    if [ -f "$review_prompt_file" ]; then
        prompt+="
---

$(cat "$review_prompt_file")"
    fi

    echo "$prompt"
}

# ============================================================================
# process_pr_reviews — handle open PRs with Copilot review comments
# ============================================================================

process_pr_reviews() {
    log "INFO" "Checking open PRs for Copilot review feedback"
    echo -e "${YELLOW}Checking open PRs for Copilot review feedback...${NC}" >&2

    local tracker="$PROJECT_ROOT/logs/pr-reviews-tracker.json"

    if [ ! -f "$tracker" ]; then
        echo '{"processed_reviews": []}' > "$tracker"
    fi

    local prs
    prs=$(gh pr list --repo "$GITHUB_REPO" --json number,headRefName,title,createdAt --state open 2>/dev/null | jq 'sort_by(.createdAt)')
    if [ $? -ne 0 ] || [ -z "$prs" ]; then
        log "WARN" "Failed to list PRs from GitHub"
        return 0
    fi

    local pr_count
    pr_count=$(echo "$prs" | jq 'length')

    if [ "$pr_count" = "0" ] || [ "$pr_count" = "null" ]; then
        log "INFO" "No open PRs to review"
        echo -e "${GREEN}No open PRs to review.${NC}" >&2
        return 0
    fi

    local reviews_processed=0

    while read -r pr <&3; do
        local pr_number
        pr_number=$(echo "$pr" | jq -r '.number')
        local branch
        branch=$(echo "$pr" | jq -r '.headRefName')
        local pr_title
        pr_title=$(echo "$pr" | jq -r '.title')

        # Only process issue/* and optimize/* branches
        if [[ "$branch" != issue/* ]] && [[ "$branch" != optimize/* ]]; then
            continue
        fi

        # Check for Copilot reviews
        local reviews
        reviews=$(gh api "repos/${GITHUB_REPO}/pulls/${pr_number}/reviews" 2>/dev/null)
        if [ $? -ne 0 ] || [ -z "$reviews" ]; then
            continue
        fi

        local copilot_review
        copilot_review=$(echo "$reviews" | jq -r '[.[] | select(.user.login == "copilot-pull-request-reviewer[bot]")] | sort_by(.submitted_at) | last')

        if [ "$copilot_review" = "null" ] || [ -z "$copilot_review" ]; then
            continue
        fi

        local review_id
        review_id=$(echo "$copilot_review" | jq -r '.id')

        # Check if already processed
        local tracked_action
        tracked_action=$(jq -r --argjson id "$review_id" '[.processed_reviews[] | select(.review_id == $id)] | last | .action // empty' "$tracker")
        if [ "$tracked_action" = "assigned" ]; then
            continue
        fi
        # Remove stale entries for this PR so we get a clean retry
        if [ -n "$tracked_action" ]; then
            log "INFO" "PR #${pr_number} still open but tracker shows '${tracked_action}' — retrying"
            jq --argjson num "$pr_number" '.processed_reviews = [.processed_reviews[] | select(.pr_number != $num)]' \
                "$tracker" > "${tracker}.tmp" && mv "${tracker}.tmp" "$tracker"
        fi

        # Fetch inline comments from Copilot
        local comments
        comments=$(gh api "repos/${GITHUB_REPO}/pulls/${pr_number}/comments" 2>/dev/null)
        if [ $? -ne 0 ] || [ -z "$comments" ]; then
            comments="[]"
        fi

        local copilot_comments
        copilot_comments=$(echo "$comments" | jq '[.[] | select(.user.login == "Copilot")]')
        local comment_count
        comment_count=$(echo "$copilot_comments" | jq 'length')

        if [ "$comment_count" = "0" ]; then
            # Clean review — assign to jdevalk
            log "INFO" "PR #${pr_number} (${pr_title}) — Copilot review clean, assigning to ${GITHUB_USER}"
            echo -e "${GREEN}PR #${pr_number} (${pr_title}) — Copilot review clean, assigning to ${GITHUB_USER}${NC}" >&2
            gh pr edit "$pr_number" --repo "$GITHUB_REPO" --add-assignee "$GITHUB_USER" 2>/dev/null
            local now
            now=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
            jq --argjson num "$pr_number" --argjson rid "$review_id" --arg t "$now" --arg a "assigned" \
                '.processed_reviews += [{"pr_number": $num, "review_id": $rid, "processed_at": $t, "action": $a}]' \
                "$tracker" > "${tracker}.tmp" && mv "${tracker}.tmp" "$tracker"
            continue
        fi

        log "INFO" "PR #${pr_number} (${pr_title}) has ${comment_count} Copilot review comments — processing"
        echo -e "${GREEN}PR #${pr_number} (${pr_title}) has ${comment_count} Copilot review comments — processing${NC}" >&2

        # Ensure clean main, then checkout PR branch
        if ! ensure_clean_main; then
            log "ERROR" "Cannot process PR #${pr_number} — working directory not clean"
            continue
        fi

        git fetch origin "$branch" 2>/dev/null
        git checkout "$branch" 2>/dev/null || git checkout -b "$branch" "origin/$branch" 2>/dev/null
        git pull --ff-only 2>/dev/null

        # Format prompt with review comments + review-fix instructions
        local prompt
        prompt=$(format_review_prompt "$pr_number" "$branch" "$copilot_comments")

        local prompt_file
        prompt_file=$(mktemp)
        local output_file
        output_file=$(mktemp)
        printf '%s' "$prompt" > "$prompt_file"

        run_claude "$prompt_file" "$output_file" 600 "sonnet"
        local exit_code=$CLAUDE_EXIT
        local output="$CLAUDE_OUTPUT"

        echo "$output"

        local action="processed"
        if [ $exit_code -ne 0 ]; then
            log "ERROR" "Claude session failed for PR #${pr_number} review (exit code: $exit_code) — assigning to ${GITHUB_USER}"
            echo -e "${YELLOW}PR #${pr_number} — Claude failed, assigning to ${GITHUB_USER}${NC}" >&2
            gh pr edit "$pr_number" --repo "$GITHUB_REPO" --add-assignee "$GITHUB_USER" 2>/dev/null
            action="assigned"
        else
            # Always assign to jdevalk (no auto-merge)
            log "INFO" "PR #${pr_number} (${pr_title}) — review fixes applied, assigning to ${GITHUB_USER}"
            echo -e "${GREEN}PR #${pr_number} (${pr_title}) — assigning to ${GITHUB_USER} for review${NC}" >&2
            gh pr edit "$pr_number" --repo "$GITHUB_REPO" --add-assignee "$GITHUB_USER" 2>/dev/null
            action="assigned"
        fi

        # Mark review as processed in tracker
        local now
        now=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
        jq --argjson num "$pr_number" --argjson rid "$review_id" --arg t "$now" --arg a "$action" \
            '.processed_reviews += [{"pr_number": $num, "review_id": $rid, "processed_at": $t, "action": $a}]' \
            "$tracker" > "${tracker}.tmp" && mv "${tracker}.tmp" "$tracker"

        reviews_processed=$((reviews_processed + 1))

        # Return to main and clean up
        cd "$PROJECT_ROOT"
        git checkout main 2>/dev/null
        git branch --merged main | grep -E '^\s+(issue|optimize)/' | xargs -r git branch -d 2>/dev/null
    done 3< <(echo "$prs" | jq -c '.[]')

    log "INFO" "PR review processing complete (${reviews_processed} reviews processed)"
}

# ============================================================================
# run_optimization — review PHP files one-by-one with daily limits
# ============================================================================

run_optimization() {
    log "INFO" "No issues remaining — entering optimization mode"
    echo -e "${YELLOW}No issues remaining. Running code optimization...${NC}" >&2

    local tracker="$PROJECT_ROOT/logs/optimization-tracker.json"
    local daily_run_limit=25
    local daily_pr_limit=10

    if [ ! -f "$tracker" ]; then
        echo '{"reviewed_files": {}, "last_run": null, "daily_runs": {}, "daily_prs": {}}' > "$tracker"
    fi

    # Check daily limits
    local today=$(date +%Y-%m-%d)
    local today_runs=$(jq -r --arg d "$today" '.daily_runs[$d] // 0' "$tracker")
    local today_prs=$(jq -r --arg d "$today" '.daily_prs[$d] // 0' "$tracker")

    if [ "$today_runs" -ge "$daily_run_limit" ]; then
        log "INFO" "Daily optimization run limit reached ($today_runs/$daily_run_limit) — skipping"
        echo -e "${GREEN}Daily optimization run limit reached ($today_runs/$daily_run_limit). Skipping.${NC}" >&2
        return 0
    fi

    if [ "$today_prs" -ge "$daily_pr_limit" ]; then
        log "INFO" "Daily optimization PR limit reached ($today_prs/$daily_pr_limit) — skipping"
        echo -e "${GREEN}Daily optimization PR limit reached ($today_prs/$daily_pr_limit). Skipping.${NC}" >&2
        return 0
    fi

    # Find the first unreviewed PHP file in src/
    local target_file=""
    local php_files
    php_files=$(find "$PROJECT_ROOT/src" -name "*.php" -type f 2>/dev/null | sort)

    while IFS= read -r file; do
        [ -z "$file" ] && continue
        local relative_file="${file#$PROJECT_ROOT/}"
        local last_commit=$(git -C "$PROJECT_ROOT" log -1 --format=%H -- "$relative_file" 2>/dev/null)
        local reviewed_commit=$(jq -r --arg f "$relative_file" '.reviewed_files[$f] // empty' "$tracker")
        if [ "$reviewed_commit" = "pr-pending" ]; then
            continue
        fi
        if [ "$last_commit" != "$reviewed_commit" ]; then
            target_file="$relative_file"
            break
        fi
    done <<< "$php_files"

    if [ -z "$target_file" ]; then
        log "INFO" "All PHP files have been reviewed — optimization cycle complete"
        echo -e "${GREEN}All PHP files reviewed. Optimization cycle complete.${NC}" >&2
        return 0
    fi

    log "INFO" "Optimization target: \"${target_file}\""
    echo -e "${YELLOW}Reviewing: ${target_file}${NC}" >&2

    cd "$PROJECT_ROOT"
    if ! ensure_clean_main; then
        return 1
    fi

    # --- Phase 1: Planning with Sonnet ---
    log "INFO" "Optimization Phase 1: Planning for ${target_file}"
    echo -e "${YELLOW}Phase 1/2: Planning optimization with Sonnet...${NC}" >&2

    local optimize_prompt_file="$PROJECT_ROOT/.claude/optimize-prompt.md"
    local base_prompt=""
    if [ -f "$optimize_prompt_file" ]; then
        base_prompt=$(cat "$optimize_prompt_file")
    else
        base_prompt="Review the following file for simplification and optimization opportunities."
    fi

    local plan_prompt="${base_prompt}

## Target File
\`${target_file}\`

## YOUR TASK: Create an Optimization Plan

You are in PLANNING MODE. Do NOT make any code changes, do NOT create branches, do NOT commit anything.

Read the target file and analyze it for optimization opportunities following the rules above.

If no improvements are needed, respond with just: STATUS: NO_CHANGES

If you find improvements, produce a plan:

1. **Current issues** — What specific problems did you find (dead code, DRY violations, unnecessary complexity, performance issues)?
2. **Proposed changes** — Describe each change precisely: what to remove, simplify, or restructure
3. **Files affected** — List every file that needs changes (usually just the target file, but include others if DRY improvements span files)
4. **Testing** — How to verify nothing broke (build commands, what to check)
5. **PR details** — Suggested branch name (\`optimize/{module-name}\`), commit message, and PR description

Be specific about the changes — include function names, line references, and describe the logic. Do NOT include actual code blocks."

    local prompt_file=$(mktemp)
    local output_file=$(mktemp)
    printf '%s' "$plan_prompt" > "$prompt_file"

    run_claude "$prompt_file" "$output_file" 300 "sonnet"

    local plan_output="$CLAUDE_OUTPUT"
    local plan_exit=$CLAUDE_EXIT

    if [ $plan_exit -ne 0 ]; then
        log "ERROR" "Optimization planning session failed (exit: $plan_exit) — file NOT marked as reviewed"
        cd "$PROJECT_ROOT"
        git checkout main 2>/dev/null
        return 1
    fi

    if echo "$plan_output" | grep -qi "STATUS:.*NO_CHANGES"; then
        log "INFO" "Optimization planning found no changes needed for ${target_file}"
        echo "$plan_output"
        CLAUDE_OUTPUT="$plan_output"
        CLAUDE_EXIT=0
    else
        # --- Phase 2: Implementation with Sonnet ---
        log "INFO" "Optimization Phase 2: Implementing for ${target_file}"
        echo -e "${YELLOW}Phase 2/2: Implementing optimization with Sonnet...${NC}" >&2

        local impl_prompt="${base_prompt}

## Target File
\`${target_file}\`

## Optimization Plan (from planning phase)

${plan_output}

## YOUR TASK: Execute the Optimization Plan

Follow the optimization plan above. The plan was created by a senior engineer who analyzed the file. Execute it step by step:

1. Create the branch as specified in the plan
2. Make all the code changes described
3. Run \`composer install\` to verify dependencies
4. Commit and push
5. Create the PR as described in the plan
6. End your response with STATUS: RESOLVED and PR_URL, or STATUS: NO_CHANGES if the plan turned out to be unnecessary"

        prompt_file=$(mktemp)
        output_file=$(mktemp)
        printf '%s' "$impl_prompt" > "$prompt_file"

        run_claude "$prompt_file" "$output_file" 300 "sonnet"

        echo "$CLAUDE_OUTPUT"
    fi

    if [ $CLAUDE_EXIT -ne 0 ]; then
        log "ERROR" "Optimization Claude session failed (exit: $CLAUDE_EXIT) — file NOT marked as reviewed"
        cd "$PROJECT_ROOT"
        git checkout main 2>/dev/null
        return 1
    fi

    # Request Copilot review and assign if a PR was created
    local created_pr=false
    local opt_pr_url=$(echo "$CLAUDE_OUTPUT" | grep -oE 'https://github.com/[^ ]*pull/[0-9]+' | head -1)
    if [ -n "$opt_pr_url" ]; then
        created_pr=true
        local opt_pr_number=$(echo "$opt_pr_url" | grep -oE '[0-9]+$')
        local opt_repo=$(echo "$opt_pr_url" | grep -oE 'github.com/[^/]+/[^/]+' | sed 's|github.com/||')
        if [ -n "$opt_pr_number" ] && [ -n "$opt_repo" ]; then
            log "INFO" "Requesting Copilot review for optimization PR #${opt_pr_number}"
            gh api -X POST "repos/${opt_repo}/pulls/${opt_pr_number}/requested_reviewers" \
                --input - <<< '{"reviewers":["copilot-pull-request-reviewer[bot]"]}' > /dev/null 2>&1 \
                || log "WARN" "Copilot review request failed for PR #${opt_pr_number}"

            log "INFO" "Assigning optimization PR #${opt_pr_number} to ${GITHUB_USER}"
            gh pr edit "$opt_pr_number" --repo "$GITHUB_REPO" --add-assignee "$GITHUB_USER" 2>/dev/null \
                || log "WARN" "Failed to assign PR #${opt_pr_number} to ${GITHUB_USER}"
        fi
    fi

    # Mark file in tracker
    cd "$PROJECT_ROOT"
    git checkout main 2>/dev/null
    git pull --ff-only 2>/dev/null
    local now=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
    local file_commit=$(git log -1 --format=%H -- "$target_file" 2>/dev/null)
    if [ "$created_pr" = true ]; then
        log "INFO" "Optimization created PR for: \"${target_file}\""
        jq --arg f "$target_file" --arg t "$now" --arg d "$today" \
            '.reviewed_files[$f] = "pr-pending" | .last_run = $t | .daily_runs[$d] = ((.daily_runs[$d] // 0) + 1) | .daily_prs[$d] = ((.daily_prs[$d] // 0) + 1)' \
            "$tracker" > "${tracker}.tmp" && mv "${tracker}.tmp" "$tracker"
    else
        log "INFO" "No optimizations found for: \"${target_file}\""
        jq --arg f "$target_file" --arg c "$file_commit" --arg t "$now" --arg d "$today" \
            '.reviewed_files[$f] = $c | .last_run = $t | .daily_runs[$d] = ((.daily_runs[$d] // 0) + 1)' \
            "$tracker" > "${tracker}.tmp" && mv "${tracker}.tmp" "$tracker"
    fi

    # Clean up merged branches
    git branch --merged main | grep -E '^\s+(issue|optimize)/' | xargs -r git branch -d 2>/dev/null

    log "INFO" "Optimization run complete for: \"${target_file}\""
}

# ============================================================================
# get_issues_with_open_prs — returns issue numbers that already have open PRs
# ============================================================================

get_issues_with_open_prs() {
    local prs
    prs=$(gh pr list --repo "$GITHUB_REPO" --state open --json headRefName,body --limit 100 2>/dev/null)
    if [ $? -ne 0 ] || [ -z "$prs" ]; then
        echo ""
        return
    fi

    # Extract issue numbers from branch names (issue/N-...) and PR bodies (Closes #N, Fixes #N, Resolves #N)
    local from_branches
    from_branches=$(echo "$prs" | jq -r '.[].headRefName' | grep -oE '^issue/[0-9]+' | grep -oE '[0-9]+')

    local from_bodies
    from_bodies=$(echo "$prs" | jq -r '.[].body // ""' | grep -oiE '(closes|fixes|resolves) #[0-9]+' | grep -oE '[0-9]+')

    echo -e "${from_branches}\n${from_bodies}" | sort -un | tr '\n' ' '
}

# ============================================================================
# fetch_issue — get an issue from GitHub (specific or oldest open)
# ============================================================================

fetch_issue() {
    if [ -n "$ISSUE_NUMBER" ]; then
        # Fetch specific issue
        gh issue view "$ISSUE_NUMBER" --repo "$GITHUB_REPO" --json number,title,body,author,createdAt,labels,comments 2>/dev/null
    else
        # Fetch oldest open issue that doesn't have in-progress or needs-info labels
        local all_issues
        all_issues=$(gh issue list --repo "$GITHUB_REPO" --state open --limit 50 \
            --json number,title,body,author,createdAt,labels,comments 2>/dev/null)

        if [ $? -ne 0 ] || [ -z "$all_issues" ]; then
            echo "[]"
            return
        fi

        # Get issue numbers that already have open PRs
        local pr_issues
        pr_issues=$(get_issues_with_open_prs)

        local exclude_json="[]"
        if [ -n "$(echo "$pr_issues" | tr -d '[:space:]')" ]; then
            exclude_json=$(echo "$pr_issues" | tr ' ' '\n' | grep -v '^$' | jq -R 'tonumber' | jq -s '.')
        fi

        # Require approved label, exclude in-progress/needs-info labels and issues with open PRs
        echo "$all_issues" | jq --argjson exclude "$exclude_json" \
            '[.[] | select(
                (.labels | map(.name) | contains(["approved"]))
                and (.labels | map(.name) | (contains(["in-progress"]) or contains(["needs-info"])) | not)
                and (.number as $n | $exclude | index($n) | not)
            )] | sort_by(.createdAt) | .[0] // empty'
    fi
}

# ============================================================================
# Main loop
# ============================================================================

if [ "$LOOP_MODE" = true ]; then
    log "INFO" "Starting loop mode — will process all open issues"
    echo -e "${GREEN}Loop mode enabled — processing all open issues${NC}" >&2
    LOOP_COUNTER=0
    FAILED_ITEMS=""
    MAX_ITEM_FAILURES=2
fi

while true; do
    if [ "$LOOP_MODE" = true ]; then
        LOOP_COUNTER=$((LOOP_COUNTER + 1))
        echo "" >&2
        echo -e "${GREEN}=== Processing item #${LOOP_COUNTER} ===${NC}" >&2
        log "INFO" "Loop iteration #${LOOP_COUNTER}"

        # Process PR reviews between issue processing
        process_pr_reviews
    fi

    # Fetch issue
    log "INFO" "Fetching issues from GitHub"
    echo -e "${YELLOW}Fetching issues from GitHub...${NC}" >&2

    ISSUE_JSON=$(fetch_issue)

    if [ -z "$ISSUE_JSON" ] || [ "$ISSUE_JSON" = "null" ] || [ "$ISSUE_JSON" = "[]" ]; then
        log "INFO" "No open issues found"
        if [ "$LOOP_MODE" = true ]; then
            local_counter=$((LOOP_COUNTER - 1))
            echo -e "${GREEN}No more open issues. Processed ${local_counter} items.${NC}" >&2
            log "INFO" "Loop completed — processed ${local_counter} items"

            if [ "$OPTIMIZE_MODE" = true ] && [ "$local_counter" -eq 0 ]; then
                run_optimization
            fi

            SCRIPT_COMPLETED=true
            break
        else
            echo -e "${GREEN}No open issues found matching criteria.${NC}" >&2

            if [ "$RUN_CLAUDE" = true ]; then
                process_pr_reviews
            fi

            if [ "$OPTIMIZE_MODE" = true ] && [ "$RUN_CLAUDE" = true ]; then
                run_optimization
            fi

            SCRIPT_COMPLETED=true
            exit 0
        fi
    fi

    DISPLAY_NUMBER=$(echo "$ISSUE_JSON" | jq -r '.number')
    DISPLAY_TITLE=$(echo "$ISSUE_JSON" | jq -r '.title')
    log "INFO" "Retrieved issue #${DISPLAY_NUMBER}: \"${DISPLAY_TITLE}\""
    echo -e "${GREEN}Retrieved issue #${DISPLAY_NUMBER}: ${DISPLAY_TITLE}${NC}" >&2

    # In loop mode, skip items that have failed too many times
    if [ "$LOOP_MODE" = true ]; then
        fail_count=$(echo "$FAILED_ITEMS" | tr ' ' '\n' | grep -c "^${DISPLAY_NUMBER}$" 2>/dev/null)
        [ -z "$fail_count" ] && fail_count=0
        if [ "$fail_count" -ge "$MAX_ITEM_FAILURES" ]; then
            log "WARN" "Issue #${DISPLAY_NUMBER} failed ${fail_count} times — adding needs-info label"
            echo -e "${RED}Issue #${DISPLAY_NUMBER} failed ${fail_count} times — skipping${NC}" >&2
            add_label "$DISPLAY_NUMBER" "needs-info"
            post_issue_comment "$DISPLAY_NUMBER" "Automated processing failed after ${fail_count} attempts. Please review manually or remove the needs-info label to retry."
            sleep 2
            continue
        fi
    fi

    # Output based on format
    if [ "$OUTPUT_FORMAT" = "json" ]; then
        echo "$ISSUE_JSON" | jq .
        SCRIPT_COMPLETED=true
        exit 0
    fi

    # Either output to stdout or run Claude
    if [ "$RUN_CLAUDE" = true ]; then
        process_exit=0
        process_issue "$ISSUE_JSON" || process_exit=$?

        # Track failures for retry limiting in loop mode
        if [ "$process_exit" -ne 0 ] && [ "$LOOP_MODE" = true ]; then
            FAILED_ITEMS="$FAILED_ITEMS $DISPLAY_NUMBER"
            log "WARN" "Issue #${DISPLAY_NUMBER} processing failed (will retry up to $MAX_ITEM_FAILURES times)"
        fi

        log "INFO" "=== Issue processing finished ==="
        SCRIPT_COMPLETED=true

        if [ "$LOOP_MODE" = true ]; then
            SCRIPT_COMPLETED=false
            echo -e "${YELLOW}Checking for next issue...${NC}" >&2
            sleep 2
        else
            break
        fi
    else
        # Dry run — just output formatted issue
        OUTPUT=$(format_issue_for_claude "$ISSUE_JSON")
        echo "$OUTPUT"
        SCRIPT_COMPLETED=true
        break
    fi
done

log "INFO" "=== Script finished (PID: $$) ==="
exit
}
