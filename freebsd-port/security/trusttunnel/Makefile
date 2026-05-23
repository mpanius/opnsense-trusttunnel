PORTNAME=	trusttunnel
DISTVERSION=	1.0.33
CATEGORIES=	security net

MAINTAINER=	mpanius@gmail.com
COMMENT=	TrustTunnel VPN endpoint (HTTPS-disguised, HTTP/2 + HTTP/3)
WWW=		https://github.com/TrustTunnel/TrustTunnel

LICENSE=	APACHE20
LICENSE_FILE=	${WRKSRC}/LICENSE

BUILD_DEPENDS=	git>0:devel/git

USES=		cargo llvm:build,lib
USE_GITHUB=	yes
GH_ACCOUNT=	TrustTunnel
GH_PROJECT=	TrustTunnel
# Vendor the exact BoringSSL commit that net/quiche uses — see UPSTREAM-NOTES.md.
# The quiche crate inside our Cargo workspace consumes this via the path expected
# by its build script.
GH_TUPLE=	google:boringssl:e1d6cd95a:boringssl/quiche/deps/boringssl

# Build only the server-side binaries from the workspace.
CARGO_INSTALL_PATH=	endpoint tools/setup_wizard

CARGO_BUILD=		yes
CARGO_BUILD_ARGS+=	-p trusttunnel_endpoint -p setup_wizard

# Tests are upstream Rust integration tests — heavy and require certs; skip in port build
CARGO_TEST=		no

# Critical for BoringSSL via the `boring` Rust crate on FreeBSD
MAKE_ENV+=		BORING_BSSL_RUST_CPPLIB=c++

# Inherit i386 SSE workaround from net/quiche
RUSTFLAGS=		${ARCH:S/i386/YES/:C/^[a-z].*//:S/YES/-C target-feature=+sse,+sse2/}

PLIST_FILES=	sbin/trusttunnel_endpoint \
		sbin/trusttunnel_setup_wizard

# Strip BoringSSL's -D_XOPEN_SOURCE=700 define — incompatible with FreeBSD libc's
# getentropy declaration (same patch net/quiche applies).
post-patch:
	@${GREP} -FRl -- '-D_XOPEN_SOURCE=700' ${WRKSRC}/boringssl 2>/dev/null | \
		${XARGS} ${REINPLACE_CMD} -e 's,-D_XOPEN_SOURCE=700,,' || ${TRUE}

# setup_wizard ships as `setup_wizard`; rename on install to avoid generic-name
# collisions in /usr/local/sbin/.
do-install:
	${INSTALL_PROGRAM} ${CARGO_TARGET_DIR}/release/trusttunnel_endpoint \
		${STAGEDIR}${PREFIX}/sbin/trusttunnel_endpoint
	${INSTALL_PROGRAM} ${CARGO_TARGET_DIR}/release/setup_wizard \
		${STAGEDIR}${PREFIX}/sbin/trusttunnel_setup_wizard

.include <bsd.port.mk>
