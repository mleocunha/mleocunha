#!/usr/bin/perl
# Install form — pick a mail-enabled parent Virtual Server.

require 'virtualmin-snappymail-lib.pl';
use strict;
use warnings;
our (%text, %in);

&ui_print_header(undef, $text{'install_title'}, "");

my $cerr = &cli_available();
&error($cerr) if $cerr;

my @parents = &list_mail_parents();
if (!@parents) {
	print &ui_alert_box($text{'install_noparents'}, 'warn'), "\n";
	&ui_print_footer('index.cgi', $text{'index_return'});
	exit;
}

print $text{'install_desc'}, "<p>\n";

print &ui_form_start('install.cgi', 'post');
print &ui_table_start($text{'install_header'}, undef, 2);

print &ui_table_row($text{'install_domain'},
	&ui_select('dom', $parents[0], [ map { [ $_, $_ ] } @parents ]));

print &ui_table_row($text{'install_version'},
	&ui_textbox('version', 'latest', 16));

print &ui_table_row($text{'install_le'},
	&ui_yesno_radio('letsencrypt', 1));

print &ui_table_end();
print &ui_form_end([ [ 'ok', $text{'install_submit'} ] ]);

&ui_print_footer('index.cgi', $text{'index_return'});
