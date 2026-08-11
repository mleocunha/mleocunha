#!/usr/bin/perl
# Run install for a parent domain.

require 'virtualmin-snappymail-lib.pl';
use strict;
use warnings;
our (%text, %in);

&ReadParse();
&error_setup($text{'install_title'});

my $dom = &validate_domain_name($in{'dom'});
&require_domain_access($dom);

my $cerr = &cli_available();
&error($cerr) if $cerr;

&ui_print_header(undef, $text{'install_title'}, "");

my @args = ('install', $dom);
my $ver = $in{'version'} // 'latest';
$ver =~ s/^\s+|\s+$//g;
if ($ver && $ver ne 'latest') {
	push(@args, '--snappy-version', $ver);
}
if (defined $in{'letsencrypt'} && $in{'letsencrypt'} eq '0') {
	push(@args, '--no-letsencrypt');
}

my ($ex, $out) = run_cli(@args);
&print_cli_result($ex, $out, &text('install_done', $dom));

print &ui_links_row([
	&ui_link('domain.cgi?dom='.&urlize($dom), $text{'index_manage'}),
	&ui_link('index.cgi', $text{'index_return'}),
]);

&ui_print_footer('index.cgi', $text{'index_return'});
