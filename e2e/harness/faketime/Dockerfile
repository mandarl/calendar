# Builds libfaketime against the oldest glibc we care about (bullseye, 2.31) so the same .so
# loads into php:7.4 (bullseye) now and php:8.x (bookworm/trixie) later. It is shipped to the
# app container through a named volume, leaving the image under test unchanged.
FROM debian:bullseye-slim

# bullseye-security is gone from the main mirrors; point only that line at the archive.
RUN sed -i 's|http://deb.debian.org/debian-security|http://archive.debian.org/debian-security|; s|http://security.debian.org/debian-security|http://archive.debian.org/debian-security|' /etc/apt/sources.list \
 && apt-get -o Acquire::Check-Valid-Until=false update \
 && apt-get install -y --no-install-recommends libfaketime \
 && mkdir -p /out \
 && cp /usr/lib/x86_64-linux-gnu/faketime/libfaketime.so.1 /out/ \
 && rm -rf /var/lib/apt/lists/*

# Default command: install the library into the mounted volume.
CMD ["sh", "-c", "cp /out/libfaketime.so.1 /faketime/libfaketime.so.1 && echo installed"]
