#!/bin/bash

[ -f emerchantpay.ocmod.zip] && rm emerchantpay.ocmod.zip

zip -r emerchantpay.ocmod.zip admin catalog image system install.json
