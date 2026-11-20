# B2B gateway in the DMZ (VLAN 40, 192.168.40.10), GNS3 node B2B-DMZ.
# nginx publishes HTTPS 443 to partners (reached through the NAT port forward
# 203.0.113.2:443 on FW-ZONA-CENTRO) and relays /wms/ to the WMS (192.168.20.5:8069),
# the only internal service the DMZ may reach (rule R-05B).
# Persistent state (GNS3 extra_volumes): /etc/b2b (private key, CSR, certificate).
FROM alpine:latest

RUN apk add --no-cache nginx openssl curl netcat-openbsd bash

COPY nginx-b2b.conf /etc/nginx/http.d/default.conf
COPY index.html /var/www/b2b/index.html
COPY bin/ /usr/local/sbin/
RUN chmod 0755 /usr/local/sbin/b2b-* && mkdir -p /etc/b2b && chmod 0700 /etc/b2b

CMD ["/usr/local/sbin/b2b-arranque"]
