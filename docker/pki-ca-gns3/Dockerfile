# Lab PKI image, used by two GNS3 nodes:
#   CA-RAIZ  offline root CA (no network link)
#   PKI-CA   issuing CA on VLAN 99 (10.99.0.10)
# CA private keys live in SoftHSM2 tokens and are created non-extractable;
# OpenSSL reaches them through the libp11 PKCS#11 provider (pkcs11prov).
# Persistent state (GNS3 extra_volumes): /srv/ca and /var/lib/softhsm/tokens.
FROM alpine:3.24

RUN apk add --no-cache openssl softhsm libp11 opensc lighttpd bash

ENV PKCS11_MODULE_PATH=/usr/lib/softhsm/libsofthsm2.so \
    SOFTHSM2_CONF=/etc/softhsm2.conf

COPY softhsm2.conf /etc/softhsm2.conf
COPY openssl-ca.cnf /etc/pki/openssl-ca.cnf
COPY lighttpd-pki.conf /etc/lighttpd/pki.conf
COPY bin/ /usr/local/sbin/
RUN chmod 0755 /usr/local/sbin/pki-* \
 && mkdir -p /srv/ca /var/lib/softhsm/tokens /var/www/pki /var/log/lighttpd \
 && chmod 0700 /srv/ca /var/lib/softhsm/tokens \
 && chown lighttpd:lighttpd /var/log/lighttpd \
 && echo '17 3 * * * /usr/local/sbin/pki-crl >> /var/log/pki/crl.log 2>&1' > /etc/crontabs/root

# PKI-CA: CRL/CA web on 80, OCSP on 2560. CA-RAIZ starts no service.
CMD ["/usr/local/sbin/pki-arranque"]
