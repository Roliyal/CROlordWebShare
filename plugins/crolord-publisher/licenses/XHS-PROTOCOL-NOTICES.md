# Xiaohongshu protocol references

The PHP integration is locally implemented. It does not bundle or execute a
remote signing service, browser automation or the following Node/Python SDKs.

Creator request signing and image-publish protocol were checked against:

- Lucas Gu, `lucasygu/redbook`, MIT; pinned commit
  `801a09847699a2bae5560eeefd901e3afdde469b`.
  https://github.com/lucasygu/redbook/blob/801a09847699a2bae5560eeefd901e3afdde469b/src/lib/creator-signing.ts
  https://github.com/lucasygu/redbook/blob/801a09847699a2bae5560eeefd901e3afdde469b/src/lib/client.ts
  Full license: redbook-MIT.txt.
- ReaJason, `ReaJason/xhs`, MIT; pinned source commit
  `f4b62d9f8e4078e631fc6e4ec8e430bc711ee9f0`.
  https://github.com/ReaJason/xhs/blob/f4b62d9f8e4078e631fc6e4ec8e430bc711ee9f0/xhs/core.py
  Upload permits, multipart upload, video-note fields and
  static note HTML state are referenced here. Full license: ReaJason-xhs-MIT.txt.

Official public authorization scope status was checked on 2026-10-25:
https://openaccount.xiaohongshu.com/docs/scope

These references describe web-session protocols, not a grant of official open
publishing access. Protocol tests are not live-account acceptance. No production
cookie, platform response, account credential or screenshot is distributed.

Creator identity endpoint and response field facts (userId/userName) were also
checked against the author-provided source below; no Rust code is bundled:
https://github.com/aki66938/XHS_RS_TOOLS/blob/910c07d6059ecfffdb50fac76574bfdc4c682251/src/api/creator/info.rs
https://github.com/aki66938/XHS_RS_TOOLS/blob/910c07d6059ecfffdb50fac76574bfdc4c682251/src/api/creator/models.rs

Creator receipt fallback also references getCreatorNoteList in the same pinned
redbook client.ts, notes/has_more in src/cli.ts, and note ID/title field facts
in src/lib/health.ts. Only the current authenticated own-account list is read;
no CLI, browser or SDK runtime is included. Listing evidence is kept separate
from full note-body and actual-media verification.

Publish-body field facts (omit empty post_loc; actual image width/height/size;
empty native timing object) were additionally checked against the author's
current Creator source, commit 23dab803a0465e35e01cf87137353a900b47e93d:
https://github.com/cv-cat/Spider_XHS/blob/23dab803a0465e35e01cf87137353a900b47e93d/apis/xhs_creator_apis.py
https://github.com/cv-cat/Spider_XHS/blob/23dab803a0465e35e01cf87137353a900b47e93d/xhs_utils/xhs_creator_util.py
Only protocol field facts are used; none of that project's Node/Python signer,
browser/device material, security program, RAP fingerprint or runtime is bundled.
The existing legacy XYW signer is not represented as current-browser parity.

Native user-verification URL field facts additionally reference the author's
platform documentation:
https://github.com/cv-cat/catbus/blob/master/docs/platforms/xhs.md
Only an actual platform-issued verifyuuid for supported type 124 is retained,
encrypted and bound to a saved publish attempt for at most ten minutes. The
admin-only entry redirects to the platform's native verification page; it does
not solve a challenge, change client identity, or assert that verification passed.
No code or browser runtime from that project is bundled.
