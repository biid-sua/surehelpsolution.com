# Social media publishing

Businesses connect their own Facebook Pages, Instagram professional accounts, LinkedIn profile and Company Pages, and Google Business Profile locations under **Social › Accounts & settings**. They then write a post once, adjust it per account, and publish it now or schedule it for any time up to 12 months ahead, in their own timezone. Why it works this way: decision D33 in [decisions.md](decisions.md).

## What a business sees

| Screen | What it does |
|---|---|
| **Social › Posts** | Month calendar in the business's timezone (`+` on a day starts a post for that day), a list with status and network filters, and **Needs approval** |
| **New post** | Choose accounts; one text with an optional different text per account; up to 10 photos from the library or uploaded; optional link; Google post type (update / offer / event), button and offer details. On the right, a preview per account with its checks ("Ready" or what to fix). Then *Schedule*, *Publish now* or *Save draft* |
| **Media library** | Upload JPG / PNG / WebP (8 MB max). Add a description (alt text) to each photo. A photo can't be deleted while a post that hasn't gone out uses it |
| **Accounts & settings** | Connect each network, choose which accounts SureHelp may post to, remove them, and set who must approve posts |

### Checks before scheduling

Each network has its own rules (`SocialNetwork::capabilities()`, `PostValidator`). Errors block scheduling. Warnings are advice.

| Network | Rules |
|---|---|
| Facebook | 63,206 characters, up to 10 photos. With photos, the link goes into the text, because there's no link preview |
| Instagram | 2,200 characters, **at least one photo** (up to 10 as a carousel), up to 30 hashtags. Links aren't clickable (warning) |
| LinkedIn | 3,000 characters. One photo, or a link preview. Reserved characters are escaped and `#words` become real hashtags |
| Google Business Profile | 1,500 characters, one photo. **No phone numbers in the text**: Google removes such posts, so use the *Call now* button instead |

### Approval

Nothing is ever published without a person scheduling it, approving it or pressing *Publish now*.

**Default ("owner"):** posts written by anyone other than an owner wait for an owner's approval:
- Business Managers
- the SureHelp team using *view as client*
- AI (from G-2)

**How approval works:**
- Owners get an in-app notification and an email, and approve or request changes on the post, or from the mobile app.
- A post sent back for changes returns as a draft with the owner's note.
- If a post's planned time has passed by the time it's approved, it's published straight away.

**Turning it off:** an owner can choose "Anyone who can manage social posts can publish directly". AI-written posts always need approval.

## How publishing runs

| Step | Detail |
|---|---|
| Queue | `social:publish-due` runs every minute. It claims each due account version once, even if runs overlap, and queues a `PublishSocialTarget` job |
| Busy network (5xx, rate limits) | Retried after 5, 15 and 60 minutes, then marked failed |
| Content refused (duplicate, policy, invalid) | Failed straight away with the network's message. Retrying wouldn't help |
| Lost access (revoked, expired, permission removed) | The account is flagged *Needs reconnecting*, owners and managers are told once, and the post is failed for that account |
| Interrupted (worker died mid-call) | After 15 minutes it is marked failed with "check before retrying". **It is never reposted automatically**, because it may already be live |
| Result | *Published*, *Partly published* or *Failed*. People with `social.manage` are notified when a post doesn't fully publish. *Retry failed accounts* re-queues only the accounts that failed |

Photos are private files. Networks download them from a signed link to `/media/{id}` that expires after 2 hours (`social.media.link_minutes`).

## Permissions

| Who | Can |
|---|---|
| Business owner | Everything, including approving posts and choosing the approval setting |
| Business manager | Connect accounts, write and schedule posts (subject to approval), media |
| Staff | No access |
| SureHelp staff | Through *view as client*. Their posts always need the owner's approval |

## One-time setup (SureHelp, not each business)

Until a network is configured, businesses see "Coming soon" for it. Redirect URIs use the production `APP_URL`.

### Facebook and Instagram (Meta)

1. In [Meta for Developers](https://developers.facebook.com/), create a **Business** app called "SureHelp" and add the **Facebook Login for Business** product.
2. Set the valid OAuth redirect URI to `https://YOUR-DOMAIN/app/social/connect/meta/callback`.
3. Request these permissions: `pages_show_list`, `pages_read_engagement`, `pages_manage_posts`, `instagram_basic`, `instagram_content_publish`, `business_management`.
4. Complete **Business Verification**, then submit **App Review** with a screencast of each permission in use: connecting, choosing Pages, scheduling a post and seeing it published. Until approval, only people with a role on the app can connect.
5. Add the credentials to `.env`:
   ```
   META_APP_ID=...
   META_APP_SECRET=...
   ```

### LinkedIn

1. In the [LinkedIn Developer Portal](https://www.linkedin.com/developers/apps), create an app associated with SureHelp's verified LinkedIn Page.
2. Add **Sign In with LinkedIn using OpenID Connect** and **Share on LinkedIn**. These are enough for personal profiles.
3. Request the **Community Management API**, which Company Pages need. It goes through a two-tier review with a screencast and needs a registered company.
4. Set the redirect URL to `https://YOUR-DOMAIN/app/social/connect/linkedin/callback`.
5. Add the credentials to `.env`:
   ```
   LINKEDIN_CLIENT_ID=...
   LINKEDIN_CLIENT_SECRET=...
   ```

Before Community Management approval, connecting still works for the person's own profile. Company Pages appear once it's granted.

### Google Business Profile

1. In the Google Cloud project used for calendar sync, request **Business Profile API** access (a form on Google's Business Profile APIs page).
2. Once access is granted, enable the *My Business Account Management*, *My Business Business Information* and *Google My Business* APIs.
3. Add `https://YOUR-DOMAIN/app/social/connect/google/callback` to the OAuth client's redirect URIs, and the `business.manage` scope to the consent screen.
4. Set `GOOGLE_BUSINESS_ENABLED=true`. It reuses the calendar client ID and secret, unless `GOOGLE_BUSINESS_CLIENT_ID` and `GOOGLE_BUSINESS_CLIENT_SECRET` are set.

## Later

- AI writing with SEO (G-2)
- Post analytics in Results (G-5)
- TikTok, YouTube Shorts, Pinterest and Threads adapters
- X as a paid add-on
- Drag-to-reschedule on the calendar
- Video

Plan limits: a plan can cap connected accounts with the `social_accounts` limit. It is checked when an account is turned on.
