# GNS3 starts Docker nodes with `su <image user> -p -- /gns3/run-cmd.sh`.
# The official image's "odoo" user has /usr/sbin/nologin, so su fails with
# "This account is currently not available". Give it a real shell.
FROM odoo:latest
USER root
RUN usermod -s /bin/bash odoo
USER odoo
