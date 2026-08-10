#!/usr/bin/perl
# Minimal ACL stub — reuse Virtualmin domain ACLs in full implementation.
require 'virtualmin-snappymail-lib.pl';

sub acl_security_form {
    my ($o) = @_;
    print &ui_table_row($text{'acl_domains'},
        &ui_radio("domains", $o->{'domains'} || 1,
            [ [1, $text{'acl_all'}], [0, $text{'acl_owned'}] ]));
}

sub acl_security_save {
    my ($o) = @_;
    $o->{'domains'} = $in{'domains'};
}
