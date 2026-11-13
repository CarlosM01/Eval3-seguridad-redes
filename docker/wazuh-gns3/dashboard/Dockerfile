# wazuh-dashboard has /sbin/nologin; GNS3 needs a real shell for su.
FROM wazuh/wazuh-dashboard:4.14.10
USER root
ARG D=/usr/share/wazuh-dashboard
# Create certs/ first: COPY --chmod=400 would also apply 400 to a directory it creates.
RUN usermod -s /bin/bash wazuh-dashboard \
 && install -d -o wazuh-dashboard -g wazuh-dashboard -m 750 ${D}/certs
COPY --chown=wazuh-dashboard:wazuh-dashboard --chmod=400 certs/root-ca.pem ${D}/certs/root-ca.pem
COPY --chown=wazuh-dashboard:wazuh-dashboard --chmod=400 certs/wazuh.dashboard.pem ${D}/certs/wazuh-dashboard.pem
COPY --chown=wazuh-dashboard:wazuh-dashboard --chmod=400 certs/wazuh.dashboard-key.pem ${D}/certs/wazuh-dashboard-key.pem
COPY --chown=wazuh-dashboard:wazuh-dashboard dashboard/opensearch_dashboards.yml ${D}/config/opensearch_dashboards.yml
COPY --chown=wazuh-dashboard:wazuh-dashboard dashboard/wazuh.yml ${D}/data/wazuh/config/wazuh.yml
# Lab credentials (see INFRAESTRUCTURA.md section 10).
ENV INDEXER_USERNAME=admin \
    INDEXER_PASSWORD=g0XCwEOvqhmcu7j4zC*RF6UMQfed9wBD \
    WAZUH_API_URL=https://192.168.30.5 \
    DASHBOARD_USERNAME=kibanaserver \
    DASHBOARD_PASSWORD=kibanaserver \
    API_USERNAME=wazuh-wui \
    API_PASSWORD=MyS3cr37P450r.*-
USER wazuh-dashboard
