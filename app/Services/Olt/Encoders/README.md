# OLT command encoder boundary

Phase B skeleton. The encoder implementations enforce only the universal
defensive floor documented below; vendor-specific rules remain pending.

The future VendorCommandEncoder abstraction must encode one CLI argument at a
time. It must never accept or return an entire user-composed command.

The controller currently assembles commands in
app/Http/Controllers/OltC300Controller.php. The four raw PPPoE password paths
are in the activate, provision, reprovision, and reconfigure flows. The
controller also interpolates names, serials, ONU types, profile values, and
derived traffic values.

The current escapeCliValue() helpers in the ZTE and Hioso services only remove
CR, LF, and double quotes. They are not a vendor-specific security contract.

Before vendor-specific rules are added:

- Obtain and record approved ZTE C300, FiberHome FHTT, and Hioso CLI rules.
- Confirm firmware versions and per-argument limits.
- Confirm whether FiberHome uses the C300 grammar in the live fleet.
- Build a fake-device transcript for accepted and rejected values.
- Define structured rejection and redaction behavior.
- Preserve all Phase 1 OLT response-boundary and snapshot tests.

Phase C must route every request-derived argument through the selected vendor
encoder. Internal numeric values still need explicit grammar validation before
command assembly. This Phase B skeleton does not add live device call-sites.

The password argument is particularly sensitive: it may be sent to the device
only through an approved provisioning flow, but it must never appear in
application logs, JSON responses, exceptions, queue payloads, or test output.

A missing encoder mapping must not silently weaken the boundary. The sprint brief
allows a temporary compatibility fallback only when it emits the documented
deprecation event and is covered by a migration test.

Do not add a live OLT connection, production environment override, or provisioning
write while this directory is in the Phase B skeleton state.

## Vendor-Rule Status (Phase B-Skeleton)

| Vendor         | Interface   | Skeleton    | Official Spec | Empirical Capture |
| -------------- | ----------- | ----------- | ------------- | ----------------- |
| ZTE C300       | ✅ (this PR) | ✅ (this PR) | ❌ PENDING     | ❌ PENDING         |
| FiberHome FHTT | ✅ (this PR) | ✅ (this PR) | ❌ PENDING     | ❌ PENDING         |
| Hioso          | ✅ (this PR) | ✅ (this PR) | ❌ PENDING     | ❌ PENDING         |

## Universal dangerous patterns enforced (skeleton only)

| Pattern                                    | Rejected? | Note                            |
| ------------------------------------------ | --------- | ------------------------------- |
| Leading `-` (any value-position)           | ✅         | CLI option injection            |
| `\r`, `\n`, `\"` in value                  | ✅         | Preserved from `escapeCliValue` |
| Control bytes `\x00`-`\x1f`, `\x7f`        | ✅         | Defense-in-depth                |
| Shell expansion tokens `` ` `` `$` `(` `)` | ✅         | SSH-CLI defensive floor         |
| Arg length >256 chars                      | ✅         | Universal defensive floor       |

## TBD Strategy

When official vendor CLI spec OR empirical capture results arrive for a vendor:

1. Override `encodeArg()` body in that vendor's encoder: replace the "Until spec arrives..." block with vendor-specific rules + comment source URL.
2. Override `maxArgLength()` to vendor's actual limit.
3. Add vendor-specific blacklist to `forbidChars()` if spec defines one.
4. Add empirical capture script at `tests/fakes/capture_{vendor}.py`.
5. Update existing 18 unit tests to assert vendor-specific behavior over universal default.

Until then: ONLY universal dangerous patterns are enforced.
