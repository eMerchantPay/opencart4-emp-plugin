#!/bin/bash

# Package name
package="emerchantpay.ocmod"

# Remove old/existing package
[ -f "${package}.zip" ] && rm "${package}.zip"

# Create package
zip -rq "${package}.zip" admin catalog image system install.json

[ -f "${package}.zip" ] && echo "The installation package (${package}.zip) was packed!"
