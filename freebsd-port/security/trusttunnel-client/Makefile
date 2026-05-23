PORTNAME=	trusttunnel-client
DISTVERSION=	1.1.4
CATEGORIES=	security net

MAINTAINER=	mpanius@gmail.com
COMMENT=	TrustTunnel VPN client (CLI; pair with security/trusttunnel)
WWW=		https://github.com/TrustTunnel/TrustTunnelClient

LICENSE=	APACHE20
LICENSE_FILE=	${WRKSRC}/LICENSE

BUILD_DEPENDS=	git>0:devel/git \
		cargo:lang/rust \
		cmake>=3.24:devel/cmake \
		ninja:devel/ninja \
		python>=3.9:lang/python3

USES=		gmake llvm:build,lib
USE_GITHUB=	yes
GH_ACCOUNT=	TrustTunnel
GH_PROJECT=	TrustTunnelClient

# IMPORTANT: this port assumes Conan recipes are already exported to the build
# user's local Conan cache. See freebsd-port/README.md for the prerequisite
# `make bootstrap_deps` step. Passing SKIP_BOOTSTRAP=1 makes the upstream
# Makefile skip its venv setup + bootstrap.
MAKE_ARGS+=	SKIP_BOOTSTRAP=1 SKIP_VENV=1 BUILD_TYPE=release

# Build client binary (and its in-tree Rust subcrates) via upstream Makefile.
do-build:
	cd ${WRKSRC} && ${SETENV} ${MAKE_ENV} ${GMAKE} ${MAKE_ARGS} build_trusttunnel_client

PLIST_FILES=	sbin/trusttunnel_client

do-install:
	${INSTALL_PROGRAM} ${WRKSRC}/build/trusttunnel/trusttunnel_client \
		${STAGEDIR}${PREFIX}/sbin/trusttunnel_client

.include <bsd.port.mk>
