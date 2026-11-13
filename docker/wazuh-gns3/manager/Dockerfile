# Runs as root with s6-overlay /init; GNS3's busybox su execs, so /init stays PID 1.
FROM wazuh/wazuh-manager:4.14.10
COPY --chmod=400 certs/root-ca.pem /etc/ssl/root-ca.pem
COPY --chmod=400 certs/wazuh.manager.pem /etc/ssl/filebeat.pem
COPY --chmod=400 certs/wazuh.manager-key.pem /etc/ssl/filebeat.key
COPY manager/ossec.conf /wazuh-config-mount/etc/ossec.conf
# Lab credentials (see INFRAESTRUCTURA.md section 10).
ENV INDEXER_URL=https://192.168.30.6:9200 \
    INDEXER_USERNAME=admin \
    INDEXER_PASSWORD=g0XCwEOvqhmcu7j4zC*RF6UMQfed9wBD \
    FILEBEAT_SSL_VERIFICATION_MODE=full \
    SSL_CERTIFICATE_AUTHORITIES=/etc/ssl/root-ca.pem \
    SSL_CERTIFICATE=/etc/ssl/filebeat.pem \
    SSL_KEY=/etc/ssl/filebeat.key \
    API_USERNAME=wazuh-wui \
    API_PASSWORD=MyS3cr37P450r.*-
