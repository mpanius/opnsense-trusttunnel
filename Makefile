PLUGIN_NAME=		trusttunnel
PLUGIN_VERSION=		0.1.0
PLUGIN_COMMENT=		TrustTunnel VPN (site-to-site server + client)
PLUGIN_DEPENDS=		trusttunnel trusttunnel-client qrencode
PLUGIN_MAINTAINER=	mpanius@gmail.com
PLUGIN_WWW=		https://github.com/mpanius/opnsense-trusttunnel

.include "${.CURDIR}/../../Mk/plugins.mk"
