#!/bin/bash
# FPP plugin callback-type advertiser.
# Called by fppd's PluginManager with "--list" at startup; must print the
# comma-separated list of callback types this plugin implements. "c++" tells
# fppd to dlopen() lib<repoName>.so and call createPlugin() in it.

if [ "$1" = "--list" ]; then
    echo "c++"
fi

exit 0
