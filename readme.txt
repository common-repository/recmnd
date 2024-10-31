=== Recmnd ===
Contributors: Neural Brothers
Donate link: http://neuralbrothers.com/
Tags: related, similar, similar posts, related posts, content recommendations, recommendations, recmnd
Requires at least: 2.6
Tested up to: 3.1
Stable tag: 1.2

The plugin allows you to use the Recmnd service (www.recmnd.com - automatic recommendations) with your WordPress blog without coding.

== Description ==

Recmnd allows you to show related content to your readers. The recommendations are real-time and change when you update your content or when you publish new articles. All recommendations are served via a widget and you control its style, the information shown for each suggestion, the number of displayed recommendations, the time period of the recommendations, and the depth of the recommendation analysis. In order to use the Recmnd service you must register for an account: <http://www.recmnd.com/plans/>. This plugin works with our trial accounts as well.

For more information, please visit the Recmnd website at <http://www.recmnd.com/>.

== Installation ==
Complete tutorial:
<http://www.recmnd.com/news/recmnd-wordpress-plugin/>

Basic installation steps

* Extract the archive into wp-content/plugins/ inside your WordPress blog folder.

* Access your WordPress Administration and go to the Plugins section to activate the Recmnd plugin.

* Use the "Recmnd" link from the WP Admin -> Plugins section to open the Recmnd WP plugin configuration page.

* Enter the API hostname, key and secret parameters as displayed in the "Settings - Account Details" section at your Recmnd account <https://www.recmnd.com/account/profile/#account-details> and confirm the configuration of the plugin by clicking on the "Initialize Recmnd" button.

* The last step would be to add the Recmnd widget to your blog pages. By default, the plugin will insert the widget at your pages upon activation. The recommendations will be shown directly below the body of your posts.

* If you want to the have the widget placed at another position at your pages, you can do this easily via the Theme Editor section in your WP Admin (Appearance -> Editor). You need to select the "Single Post" (single.php) template file for your blog theme, and insert the following code snippet in the exact place where you want your recommendations to appear (probably you would like to have the widget displayed right underneath the text of the blog post):
`<!-- Start recmnd -->
<? php recmnd_body() ?>
<!-- End recmnd -->`

* Save the changes to the "Single Post" template, and that's it - you are ready to serve dynamic content recommendations at your WordPress website.

== Changelog ==

= 1.2 =
* Fully automated initialization of the personalized Recmnd engine.

= 1.1 =
* Automatic analysis of your content. Manual importing of your data to your Recmnd account is no longer needed.

= 1.0 =
* Automatic widget insertion upon the activation of the Recmnd plugin

= 0.9 =
* Initial plugin release

== Upgrade Notice ==

= 1.2 =
The new version of the Recmnd plugin completely automates the initialization of your recommendation engine. You no longer need to export your published data and to import it at your Recmnd account manually. With version 1.2 of the plugin, this procedure is automated as well.