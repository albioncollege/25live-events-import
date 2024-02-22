## 25Live x Tribe Events Calendar Import
This plugin allows the importing of a list of events from a [25Live Events](https://collegenet.com/products/scheduling/25live.html) feed URL, directly into [Tribe Events Calendar](https://theeventscalendar.com/) in WordPress.

### Setting the Feed URL
There are two methods to specify the feed URL from 25Live.

1. **Directly in the plugin code:** Edit the feed URL in the 25live-event-import.php file to specify your feed.
2. **Use Options Page in WordPress:** To manage the URL in the WordPress dashboard, you'll just need to add another plugin called [Advanced Custom Fields](https://wordpress.org/plugins/advanced-custom-fields/) - it's free (the pro version is great though!) and allows the easy creation of Options pages or custom fieldsets in WordPress. Just enable the plugin, and the options page for this plugin will show up under Plugins > 25Live Events Import in the dashboard. That interface will allow you to enable/disable the cron task, and specify your own feed URL.

Once it's all set up, everything just happens in the background. You can look into the plugin code to adjust how fields are mapped in from feeds, but by default, the plugin just brings in the event title, description, and dates, and adds all custom fields as custom fields in WP.