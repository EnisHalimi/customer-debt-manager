# Changelog

All notable changes to this project will be documented in this file.

## [1.0.2] - 2025-11-07

### Added
- Smart modal notification system that shows messages inside modals when appropriate
- Context-aware notifications for better user experience
- Auto-dismissible success notifications with 5-second timeout
- Proper notification cleanup when opening/closing modals

### Fixed
- Fixed debt validation logic where customers with existing debt were incorrectly told they had no debt to reduce
- Fixed currency symbol display issues in AJAX responses (€ instead of &euro;)
- Fixed HTML entity encoding in error messages (&quot; displaying instead of proper quotes)
- Improved fallback mechanism for debt amount detection when form data is missing

### Improved
- Replaced intrusive JavaScript alerts with WordPress-style admin notifications
- Enhanced user experience with non-blocking notifications
- Better error message positioning within modal contexts
- Improved validation feedback for debt adjustment operations
- Cleaner notification styling that matches WordPress admin standards

### Technical
- Added robust fallback logic for debt validation using displayed balance data
- Improved AJAX response handling with proper currency formatting
- Enhanced notification system with container detection (modal vs page-level)
- Better form data management and cleanup

## [1.0.1] - 2025-11-07

### Fixed
- Fixed plugin installation issues with proper zip file structure
- Resolved "user has no debt" alert issues during debt adjustment
- Fixed euro symbol display corruption throughout the interface
- Corrected HTML entity encoding in translatable strings

### Improved
- Enhanced debt adjustment validation logic
- Better currency symbol handling with WooCommerce integration
- Improved error message formatting and display

## [1.0.0] - 2025-11-07

### Added
- Initial release of Customer Debt Manager
- Complete debt tracking system for WooCommerce
- Admin interface for debt management
- Customer frontend integration via My Account page
- HPOS (High-Performance Order Storage) compatibility
- COD debt creation functionality
- Manual debt adjustment features
- Payment recording and tracking
- Comprehensive search and filtering capabilities