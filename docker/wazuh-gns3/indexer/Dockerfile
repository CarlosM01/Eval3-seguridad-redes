# GNS3 starts Docker nodes with `su <image user> -p -- /gns3/run-cmd.sh`.
# wazuh-indexer has /sbin/nologin, so give it a real shell (same fix as odoo-gns3).
# Certificates and config are baked in because GNS3 has no bind mounts.
FROM wazuh/wazuh-indexer:4.14.10
USER root
RUN usermod -s /bin/bash wazuh-indexer
ARG D=/usr/share/wazuh-indexer/config
COPY --chown=wazuh-indexer:wazuh-indexer --chmod=400 certs/root-ca.pem ${D}/certs/root-ca.pem
COPY --chown=wazuh-indexer:wazuh-indexer --chmod=400 certs/wazuh.indexer.pem ${D}/certs/wazuh.indexer.pem
COPY --chown=wazuh-indexer:wazuh-indexer --chmod=400 certs/wazuh.indexer-key.pem ${D}/certs/wazuh.indexer.key
COPY --chown=wazuh-indexer:wazuh-indexer --chmod=400 certs/admin.pem ${D}/certs/admin.pem
COPY --chown=wazuh-indexer:wazuh-indexer --chmod=400 certs/admin-key.pem ${D}/certs/admin-key.pem
COPY --chown=wazuh-indexer:wazuh-indexer indexer/opensearch.yml ${D}/opensearch.yml
COPY --chown=wazuh-indexer:wazuh-indexer indexer/internal_users.yml ${D}/opensearch-security/internal_users.yml
ENV OPENSEARCH_JAVA_OPTS="-Xms1g -Xmx1g"
USER wazuh-indexer
