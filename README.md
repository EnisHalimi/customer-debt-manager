# Customer Debt Manager for WooCommerce

A WordPress plugin that allows customers to order on debt/credit and provides comprehensive debt tracking for both administrators and customers. Fully integrated with WooCommerce and compatible with High-Performance Order Storage (HPOS).

## Features

### For Customers:

- **Cash on Delivery (COD) Debt Creation**: Automatically creates debt records for COD orders
- **WooCommerce My Account Integration**: View debt status within the familiar My Account area
- **Order Management**: View all orders with debt status and payment history
- **Debt Details**: Modal popups with detailed debt and payment information
- **Order Navigation**: Direct links to view order details when permitted

### For Administrators:

- **Professional Admin Interface**: WordPress-standard table with comprehensive debt management
- **Search & Filter**: Find debts by customer name, email, order ID, or status
- **Sorting**: Sort by any column (customer, order ID, amount, status, dates)
- **Payment Processing**: AJAX-powered payment recording with instant updates
- **Customer Management**: View and manage all customer debts from one interface
- **Export & Reporting**: Easy overview of outstanding debts and payments

### Technical Features:

- **HPOS Compatibility**: Full support for WooCommerce High-Performance Order Storage
- **Automatic COD Processing**: Seamless integration with WooCommerce order processing
- **Secure Access**: Proper user authentication and permission checking
- **Clean Database Design**: Optimized tables with proper relationships
- **AJAX Integration**: Smooth user experience without page reloads
- **Responsive Design**: Works on desktop and mobile devices

## Installation

1. **Upload the Plugin**:

   - Upload the `customer-debt-manager` folder to `/wp-content/plugins/`
   - Or install via WordPress admin: Plugins > Add New > Upload Plugin

2. **Activate the Plugin**:

   - Go to WordPress admin > Plugins
   - Find "Customer Debt Manager" and click "Activate"

3. **Requirements Check**:

   - The plugin will automatically check for WooCommerce
   - If WooCommerce is not active, activation will fail with a helpful message

4. **Automatic Setup**:
   - Database tables are created automatically on activation
   - WooCommerce My Account integration is set up automatically
   - No additional configuration needed

## Usage

### For Customers:

1. **Place COD Orders**: Any Cash on Delivery order automatically creates a debt record
2. **View Debt Status**: Go to WooCommerce My Account > My Debt to see all orders and debt status
3. **Check Details**: Click "Debt Details" button for payment history and information
4. **View Orders**: Click "View Order" to see full order details (when permitted)

### For Administrators:

1. **Access Admin Panel**: Go to WordPress admin > Customer Debt Manager
2. **Search Debts**: Use the search box to find specific customers or orders
3. **Filter by Status**: Use status dropdown to filter pending/paid debts
4. **Record Payments**: Click "Record Payment" to log customer payments
5. **Sort Data**: Click column headers to sort by any field

## Database Structure

The plugin creates two custom tables:

### `wp_customer_debts`

- Stores main debt records linked to orders
- Tracks amounts, status, and dates
- Links to WooCommerce customers and orders

### `wp_customer_debt_payments`

- Stores payment history for each debt
- Tracks payment amounts, dates, and notes
- Linked to debt records for complete audit trail

## Compatibility

- **WordPress**: 5.0+ (tested up to 6.3)
- **WooCommerce**: 4.0+ (tested up to 8.0)
- **PHP**: 7.4+ recommended
- **HPOS**: Full compatibility with High-Performance Order Storage
- **Multisite**: Compatible (not extensively tested)

## Support

For support, feature requests, or bug reports, please contact the plugin developer.

## Changelog

### Version 1.0.0

- Initial release
- Full WooCommerce integration
- HPOS compatibility
- Admin and customer interfaces
- Automatic COD debt creation
- Search, filter, and sort functionality
- AJAX payment processing
- Responsive design

## License

This plugin is licensed under the GPL v2 or later.

---

**Note**: This plugin requires WooCommerce to function. Make sure WooCommerce is installed and activated before installing this plugin.
