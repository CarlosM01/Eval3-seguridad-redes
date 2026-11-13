#!/bin/sh
# Build the three Wazuh images used by the GNS3 nodes. Run from this directory.
# Certificates: see certs.yml (generated with wazuh/wazuh-certs-generator:0.0.5).
set -e
for c in indexer manager dashboard; do
  docker build -q -f "$c/Dockerfile" -t "wazuh-$c-gns3:4.14.10" .
done
