# Changelog

All notable changes to this project will be documented in this file.

## [1.0.6] - 2025-01-27

### Security Enhanced

- **Protected sensitive deployment settings** - API keys and sensitive credentials are now masked by default
- **Secure field display** - Sensitive fields (API Key, Webhook URL, Project ID) show masked values (••••) instead of plain text
- **Visibility toggle** - Added show/hide button to reveal masked values when needed
- **Edit protection** - Confirmation required before modifying sensitive fields
- **Form submission protection** - Warning and confirmation when saving modified sensitive fields
- **Automatic value restoration** - Original values are preserved if masked fields are submitted without changes

### Enhanced

- **Improved settings security** - Prevents accidental exposure of API keys and credentials
- **Better UX for sensitive data** - Clear visual indicators and warnings for protected fields
- **Mobile-responsive security controls** - Security features work seamlessly on all devices

### Fixed

- **Removed infinite gradient animation** - Fixed the shimmer animation on the edit field button that was running continuously
- Button animations now only trigger on hover/active states, improving performance and visual clarity

### Technical Changes

- Added `vercel-sensitive-field-wrapper` component for secure field display
- Implemented JavaScript handlers for visibility toggle and edit protection
- Enhanced form submission handling to preserve original values
- Added CSS styles for security indicators and controls
- Removed infinite `shimmer` animation from `.vercel-edit-field::before` pseudo-element

---

## [1.0.5] - 2024-12-XX

### Refactored

- **Theme page management in preview manager** - Improved handling of theme page disabling in headless WordPress setup
- Moved theme page disabling hooks to the constructor for early execution
- Enhanced the `remove_themes_menu_item` method to ensure complete removal of the themes menu and its submenus
- Improved the `redirect_themes_page` method to check for various conditions before redirecting
- Added a new method to block direct access to the themes page and a CSS fallback to hide the Appearance menu
- Ensured all changes respect the settings for disabling the theme page

### Technical Changes

- Better hook registration timing for theme page management
- More robust theme menu removal with submenu handling
- Enhanced redirect logic with multiple condition checks
- CSS fallback for menu hiding when JavaScript is disabled

---

## [1.0.4] - 2024-12-XX

### Enhanced

- **Improved language prefix management in permalinks** - Full support for multilingual sites (WPML, Polylang, etc.)
- Primary language permalinks no longer include language prefix (e.g., `/slug` instead of `/fr/slug`)
- Secondary language permalinks automatically include their prefix (e.g., `/en/slug`, `/it/slug`)
- Automatic detection of primary language for all supported multilingual plugins

### Technical Changes

- Added `get_post_language_code()` function to detect the language of a specific post
- Enhanced `get_primary_language_code()` function with extended support for WPML, Polylang, TranslatePress, Weglot, and qTranslate-X
- Modified `rewrite_permalink()` to automatically add language prefixes only for secondary languages
- Full WPML support with multiple language detection methods
- Better handling of multilingual sites without language prefixes in original WordPress permalinks

### Fixed

- Fixed language prefix management logic for multilingual sites
- Permalinks now work correctly even when WordPress doesn't generate language prefixes in URLs

---

## [1.0.3] - 2024-10-15

### Fixed

- **Fixed admin bar "Visit Site" button URL** - Now correctly redirects to production URL instead of WordPress URL
- Resolved issue where admin bar URLs were not being modified due to hooks being registered too late
- Fixed JavaScript injection timing to ensure admin bar links are updated after render

### Enhanced

- Improved hook registration by moving admin bar fixes directly to constructor
- Added JavaScript-based URL modification for better compatibility
- Admin bar modification now works with all themes and configurations
- Better debugging with console logs for URL updates

### Technical Changes

- Moved `admin_footer` and `wp_footer` hooks to class constructor for earlier registration
- Simplified URL modification approach using client-side JavaScript
- Removed complex filter-based approaches that were causing conflicts
- Added multiple retry attempts (100ms, 500ms, 1000ms) to catch late-rendered elements

---

## [1.0.2] - 2024-01-15

### Fixed

- Fixed preview functionality not working on posts and pages
- Corrected post type validation logic to always include WordPress default post types

### Enhanced

- Improved post type filtering logic for better compatibility
- Posts and pages now always show preview functionality
- Custom post types still filtered based on URL availability

---

## [1.0.1] - 2024-01-15

### Fixed

- Fixed AJAX action name mismatch causing 400 error when clearing cache
- Resolved cache clearing functionality not working

### Added

- **Real Vercel cache clearing** via API integration
- **Custom post type support** for preview functionality
- **Preview metabox display in custom post type editors**
- Smart post type filtering based on URL availability
- Preview buttons in custom post type publish boxes

### Enhanced

- Cache clearing now works with Vercel API instead of just timestamp updates
- Preview metabox appears on all public custom post types with URLs
- Better error handling and user feedback for cache operations

---

## [1.0.0] - 2024-01-01

### Added

- Initial release of Vercel WP plugin
- Unified settings page with tabbed interface
- Deploy tab (default) with full deployment functionality
- Preview tab with comprehensive preview features
- **Automatic permalink rewriting** - All permalinks use production URL
- **Smart permalink filters** - Works with posts, pages, and custom post types
- **Admin bar integration** - "Visit Site" links use production URL
- **Public route redirection** - All public pages redirect to production
- Real-time deployment status tracking
- Split-screen preview interface with device simulation
- Preview buttons in post editor and admin bar
- URL mapping between WordPress and Vercel
- Headless WordPress functionality
- URL replacement tool for migrations
- Cache management with Vercel API integration
- ACF support for serialized data
- Connection diagnostics and testing tools
- Comprehensive documentation and configuration guides

### Technical Features

- One-click deployment to Vercel
- Real-time deployment status monitoring
- Deployment history with detailed information
- Admin bar deploy button for quick access
- Vercel services status monitoring
- Complete API integration with Vercel
- Device simulation (Desktop, Tablet, Mobile)
- Preview from post editor
- Smart cache clearing via Vercel API
- Automatic permalink rewriting for all post types
- Custom post type support with URL validation
- AJAX endpoints for all functionality
- Secure nonce verification for all requests
- Multi-language support (English/French)

### Security

- Secure AJAX implementation with nonce verification
- User capability checks for all admin functions
- Input sanitization and validation
- Error logging without exposing sensitive data

### Performance

- Efficient cache management
- Optimized API calls with proper error handling
- Smart polling with adaptive intervals
- Background processing for deployments

---

## Support

For issues, feature requests, or questions:

- Check the configuration guide in each tab
- Review the Vercel documentation links provided
- Ensure your Vercel credentials are correct
- Use the connection test tools in the Preview tab
- **For permalink issues**: Ensure WordPress permalinks are configured (not using `?p=` structure)

### Troubleshooting Permalinks

If permalinks are not being rewritten:

1. **Check WordPress permalinks**: Go to `Settings > Permalinks` and ensure you're not using the default `?p=` structure
2. **Recommended structures**: `/%postname%/`, `/%year%/%monthnum%/%postname%/`, or `/%category%/%postname%/`
3. **Verify Production URL**: Make sure it's correctly set in the Preview tab
4. **Clear cache**: Use WordPress cache clearing tools if you have caching plugins

---

## Important Disclaimers

### Official Status

- **This is NOT an official Vercel plugin**
- **This is a third-party plugin** developed independently
- **Not affiliated with, endorsed by, or officially supported by Vercel Inc.**
- Vercel is a trademark of Vercel Inc.

### Technical Disclaimer

- This plugin uses Vercel's public APIs and webhook functionality
- All integrations are based on Vercel's official documentation
- Plugin functionality depends on Vercel's API availability and changes
- Users are responsible for ensuring compliance with Vercel's Terms of Service

### Support Disclaimer

- Support is provided by the plugin author, not by Vercel
- Issues should be reported to the plugin's GitHub repository
- For Vercel-specific issues, consult Vercel's official support channels
