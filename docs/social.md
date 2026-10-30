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

Each network's app ID and secret can be typed into **Admin › Connections** instead of `.env` (D104).
Saved values are encrypted, override `.env`, and take effect straight away. The page lists both
redirect URLs to add in each network's app: `/app/social/connect/{network}/callback` for
businesses and `/admin/social/connect/{network}/callback` for SureHelp's own accounts.

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

### X (Twitter)

1. In the [X Developer Portal](https://developer.x.com/), create a project and app with a plan that allows posting and media upload.
2. Under **User authentication settings**, choose OAuth 2.0, type *Web App*, with **Read and write** permissions.
3. Add both redirect URLs (see above).
4. Copy the OAuth 2.0 **Client ID** and **Client Secret** into Admin › Connections (or `X_CLIENT_ID`, `X_CLIENT_SECRET`).

Posts are up to 280 characters with up to 4 pictures or one video of up to 140 seconds.

### YouTube

1. In the Google Cloud project, enable the **YouTube Data API v3**.
2. Use the same OAuth client or a new *Web application* client, with both redirect URLs, and add the `youtube.upload` and `youtube.readonly` scopes to the consent screen.
3. Put the client ID and secret into Admin › Connections (or `YOUTUBE_CLIENT_ID`, `YOUTUBE_CLIENT_SECRET`).
4. Until Google audits the app, uploads from it stay private. Request the audit before going live.

YouTube posts must have a video. The first line of the text is the title, the rest the description.

## Videos (D104)

The media library takes MP4, MOV and M4V files up to `SOCIAL_VIDEO_MAX_MB` (default 100 MB;
PHP's `upload_max_filesize` and `post_max_size` must allow it). The length is read from the file.

| Network | Video posts |
|---|---|
| Facebook | Yes, as a Page video |
| Instagram | Yes, as a Reel, up to 15 minutes |
| LinkedIn | Yes, up to 30 minutes |
| YouTube | Required |
| X | Yes, up to 140 seconds |
| Google Business Profile | No (pictures only) |

## SureHelp's own social media (D104)

**Admin › Social media** runs SureHelp's accounts with the same Posts, Media library and Accounts
screens businesses use, plus a **Schedule** tab. SureHelp is an internal business
(`organizations.is_internal`): it never appears in admin lists or reports, and has no plan limits.

- **Schedule:** times each day (up to 12), days, which accounts, and the mix of text, picture and
  video posts. Every 15 minutes `social:house-schedule` writes the posts due in the next 24 hours,
  one per time, with the AI chosen in Connections (Claude or ChatGPT).
- **Pictures:** from the library only; from the library with ChatGPT making a new one when every
  picture was used in the last 30 days; or always new. Videos come from the library, least
  recently used first.
- **Topics** are used in turn; recent posts are given to the AI so it doesn't repeat them.
- **Approval:** off, posts go out at their time; on, they wait under Posts for a staff member.
  Either way any post can be edited or cancelled until it goes out.
- **Prepare the next post now** writes the next free time straight away, to check the result.
- Staff need `social.view` to look and `social.manage` to change anything or connect accounts.

## Writing with AI (G-2, D66)

Needs `ANTHROPIC_API_KEY`. Without it the panels say "Writing with AI isn't switched on yet".

- **Write with AI** (in the post composer): the business describes the post in a sentence or two and picks a tone. The AI writes a version for each network chosen:
  - Instagram gets hashtags and no links;
  - Google gets no hashtags and no phone numbers;
  - every version stays within the network's length limit.

  The first version goes in the shared text. A network whose version is different gets its own text.
- **Rewrite buttons** under the text: Shorter, Friendlier, More professional, Add a call to action. The result fits the strictest network chosen.
- **Plan the month with AI** (Posts page): the AI drafts 1–5 posts a week for this month or one of the next three.
  - Drafts are spread from tomorrow to the month's end, each with an idea and a photo suggestion.
  - They appear in the calendar as **AI drafts**. Drafts are never published.
  - A manager scheduling one sends it to the owner for approval. An owner scheduling one approves it.
- **What the AI may say:** only the business's public details: profile, address, service area, services and public knowledge. Internal and team-only knowledge never reach it.
- **Checked before posting:** a "Check before posting" list flags:
  - claims the AI couldn't back up;
  - phone numbers, prices, discounts and "years in business" that aren't in those details;
  - text that breaks a network's rules.
- **Brand voice** (*Accounts & settings*): free text, up to 1,000 characters, that steers every post.
- **Labels:** posts show *AI draft*, *Written by AI* or *AI-assisted* in the calendar, the list, the post page and the API (`ai_label`).
- **Allowance:**
  - 150,000 tokens a month per business (`SOCIAL_AI_MONTHLY_TOKENS`), or the plan's `social_ai_tokens` limit;
  - counted in the business's timezone, shown as a percentage in the composer;
  - a failed request uses none.

## Link in bio and last-minute openings (D69)

- **Link in bio:** *Accounts & settings* › Link in bio switches on a page at `/b/{business-slug}`. It has Book online, Call, Send us a message, the website and up to six links of the business's own. Booking and messages open the website snippet on the page.
- **Opening the snippet from any page:** `#surehelp-book` (or `-chat`, `-call`, `-lead`) as a link or in the page address, or `SureHelp.open('book')` from a script.
- **Last-minute openings:** the Posts page shows free times in the next three open days. "Post about these openings" starts a post with the times and the booking link.

## Later

- Post analytics in Results (G-5)
- TikTok, Pinterest and Threads adapters
- Drag-to-reschedule on the calendar

Plan limits: a plan can cap connected accounts with the `social_accounts` limit. It is checked when an account is turned on.
