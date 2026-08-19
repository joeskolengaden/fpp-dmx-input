#!/bin/bash
# Run by FPP's plugin installer immediately after `git clone`.
set -e

cd "$(dirname "$0")/.."
make SRCDIR=/opt/fpp/src

echo "fpp-dmx-input built. Restart fppd (or reboot) to load it."
