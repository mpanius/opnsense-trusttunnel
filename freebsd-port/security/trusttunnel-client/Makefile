PORTNAME=	trusttunnel-client
DISTVERSION=	1.1.5-rc.6
CATEGORIES=	security net

MAINTAINER=	mpanius@gmail.com
COMMENT=	TrustTunnel VPN client (CLI; pair with security/trusttunnel)
WWW=		https://github.com/TrustTunnel/TrustTunnelClient

LICENSE=	APACHE20
LICENSE_FILE=	${WRKSRC}/LICENSE

BUILD_DEPENDS=	cargo:lang/rust \
		cmake>=3.24:devel/cmake \
		git>0:devel/git \
		ninja:devel/ninja \
		python313>=3.13:lang/python313

USES=		gmake llvm:build
USE_GITHUB=	yes
GH_ACCOUNT=	TrustTunnel
GH_PROJECT=	TrustTunnelClient
GH_TAGNAME=	v${DISTVERSION}

# IMPORTANT: this port assumes Conan recipes are already exported to the build
# user's local Conan cache. See freebsd-port/README.md for the prerequisite
# bootstrap script. Passing SKIP_BOOTSTRAP=1 makes the upstream Makefile skip
# its venv setup + bootstrap.
MAKE_ARGS+=	BUILD_DIR=build \
		BUILD_TYPE=release \
		CMAKE_ARGS=-DTT_CLIENT_VERSION=${DISTVERSION} \
		SKIP_BOOTSTRAP=1 \
		SKIP_VENV=1
CONAN_HOME?=	${HOME}/.conan2
MAKE_ENV+=	CONAN_HOME=${CONAN_HOME}

# Build client binary (and its in-tree Rust subcrates) via upstream Makefile.
do-build:
	cd ${WRKSRC} && ${SETENV} -u DESTDIR -u MAKEFLAGS ${MAKE_ENV} \
		DESTDIR= MAKEFLAGS= ${GMAKE} ${MAKE_ARGS} DESTDIR= build_trusttunnel_client

PLIST_FILES=	sbin/trusttunnel_client

do-install:
	${INSTALL_PROGRAM} ${WRKSRC}/build/trusttunnel/trusttunnel_client \
		${STAGEDIR}${PREFIX}/sbin/trusttunnel_client

.include <bsd.port.mk>
