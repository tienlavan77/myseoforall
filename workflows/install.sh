#!/usr/bin/env bash
# My SEO For All - Phase 1 environment bootstrap
#
# Recommended invocation from the WordPress plugins directory:
#   bash myseoforall/workflows/install.sh
#
# The script is intentionally idempotent: existing manifests and source files
# are never overwritten. It prepares the project and installs Composer deps;
# it does not install OS packages or execute downloaded code.

set -Eeuo pipefail
IFS=$'\n\t'

SCRIPT_DIR="$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
PROJECT_DIR="$(CDPATH= cd -- "${SCRIPT_DIR}/.." && pwd -P)"

PHP_MIN_MAJOR=8
PHPUNIT_CONSTRAINT='^9.6'

usage() {
    cat <<'EOF'
My SEO For All - cài đặt nền móng Giai đoạn 1

Usage:
  bash workflows/install.sh [options]

Options:
  --project-dir DIR  Chỉ định thư mục plugin thay vì thư mục chứa script.
  --skip-composer    Chỉ khởi tạo file/thư mục, không chạy Composer.
  --help             Hiển thị trợ giúp.

Script không cài package hệ điều hành, không ghi đè file đã có và không cài
Anthropic SDK ở Giai đoạn 1.
EOF
}

SKIP_COMPOSER=0
while (($# > 0)); do
    case "$1" in
        --project-dir)
            [[ $# -ge 2 ]] || { echo "Thiếu giá trị cho --project-dir" >&2; exit 2; }
            PROJECT_DIR="$(CDPATH= cd -- "$2" && pwd -P)"
            shift 2
            ;;
        --skip-composer)
            SKIP_COMPOSER=1
            shift
            ;;
        --help|-h)
            usage
            exit 0
            ;;
        *)
            echo "Tùy chọn không hợp lệ: $1" >&2
            usage >&2
            exit 2
            ;;
    esac
done

info() { printf '[myseo-install] %s\n' "$*"; }
warn() { printf '[myseo-install][WARNING] %s\n' "$*" >&2; }
fail() { printf '[myseo-install][ERROR] %s\n' "$*" >&2; exit 1; }

[[ -d "$PROJECT_DIR" ]] || fail "Không tìm thấy thư mục plugin: $PROJECT_DIR"

if ! command -v php >/dev/null 2>&1; then
    fail "Chưa tìm thấy PHP. Hãy cài PHP 8.x rồi chạy lại script."
fi

PHP_VERSION="$(php -r 'echo PHP_VERSION;')"
PHP_MAJOR="$(php -r 'echo PHP_MAJOR_VERSION;')"
if [[ "$PHP_MAJOR" -lt "$PHP_MIN_MAJOR" ]]; then
    fail "PHP $PHP_VERSION không đáp ứng yêu cầu PHP 8.x."
fi
info "PHP $PHP_VERSION"

mkdir -p \
    "$PROJECT_DIR/src/Core/Contracts" \
    "$PROJECT_DIR/src/Core/Configuration" \
    "$PROJECT_DIR/src/Core/Support" \
    "$PROJECT_DIR/src/Admin/Notices" \
    "$PROJECT_DIR/src/WooCommerce" \
    "$PROJECT_DIR/src/Infrastructure/WordPress" \
    "$PROJECT_DIR/src/Infrastructure/Persistence" \
    "$PROJECT_DIR/tests/Unit" \
    "$PROJECT_DIR/tests/Integration" \
    "$PROJECT_DIR/languages"

COMPOSER_JSON="$PROJECT_DIR/composer.json"
PHPUNIT_XML="$PROJECT_DIR/phpunit.xml"

if [[ ! -e "$COMPOSER_JSON" ]]; then
    cat > "$COMPOSER_JSON" <<'JSON'
{
  "name": "myseoforall/wordpress-plugin",
  "description": "My SEO For All - SEO Assistant for WordPress and WooCommerce",
  "type": "wordpress-plugin",
  "license": "proprietary",
  "require": {
    "php": ">=8.0"
  },
  "require-dev": {
    "phpunit/phpunit": "^9.6"
  },
  "autoload": {
    "psr-4": {
      "MySeoForAll\\": "src/"
    }
  },
  "autoload-dev": {
    "psr-4": {
      "MySeoForAll\\Tests\\": "tests/"
    }
  },
  "config": {
    "sort-packages": true,
    "allow-plugins": {}
  }
}
JSON
    info "Đã tạo composer.json tối thiểu (PHPUnit 9.6 cho PHP 8.0+)."
else
    info "Giữ nguyên composer.json hiện có."
fi

if [[ ! -e "$PHPUNIT_XML" ]]; then
    cat > "$PHPUNIT_XML" <<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="vendor/autoload.php" colors="true">
    <testsuites>
        <testsuite name="My SEO For All">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
</phpunit>
XML
    info "Đã tạo phpunit.xml tối thiểu."
else
    info "Giữ nguyên phpunit.xml hiện có."
fi

if (( SKIP_COMPOSER == 1 )); then
    warn "Đã bỏ qua Composer theo yêu cầu --skip-composer."
    info "Có thể chạy lại không có tùy chọn này sau khi cài Composer."
    exit 0
fi

if ! command -v composer >/dev/null 2>&1; then
    warn "Chưa tìm thấy Composer; phần thư mục và manifest đã được chuẩn bị."
    warn "Cài Composer theo hướng dẫn chính thức rồi chạy lại script."
    exit 1
fi

info "Composer $(composer --version --no-ansi | head -n 1)"
cd "$PROJECT_DIR"
composer validate --no-check-publish --no-interaction
composer install --prefer-dist --no-interaction --no-progress --no-scripts

if [[ -x "$PROJECT_DIR/vendor/bin/phpunit" ]]; then
    info "Đã cài PHPUnit: $PROJECT_DIR/vendor/bin/phpunit"
else
    warn "Composer hoàn tất nhưng chưa thấy vendor/bin/phpunit. Kiểm tra composer.json và output phía trên."
fi

info "Hoàn tất chuẩn bị Giai đoạn 1 tại: $PROJECT_DIR"
info "Chưa có mã nguồn plugin; bước tiếp theo là triển khai bootstrap và test nền tảng."
