"""Structured error model for virtualmin-snappymail."""

from __future__ import annotations


class VSMError(Exception):
    """Base application error with stable machine code."""

    code = "VSM-ERROR"

    def __init__(self, message: str, *, code: str | None = None) -> None:
        super().__init__(message)
        if code:
            self.code = code
        self.message = message

    def to_dict(self) -> dict:
        return {"error": self.code, "message": self.message}


class DomainInvalidError(VSMError):
    code = "VSM-DOMAIN-INVALID"


class ParentMissingError(VSMError):
    code = "VSM-PARENT-MISSING"


class ParentNoMailError(VSMError):
    code = "VSM-PARENT-NO-MAIL"


class SubserverConflictError(VSMError):
    code = "VSM-SUB-CONFLICT"


class MailOnSubserverError(VSMError):
    code = "VSM-MAIL-ON-SUB"


class AlreadyInstalledError(VSMError):
    code = "VSM-ALREADY-INSTALLED"


class NotManagedError(VSMError):
    code = "VSM-NOT-MANAGED"


class DownloadError(VSMError):
    code = "VSM-DOWNLOAD"


class VirtualminError(VSMError):
    code = "VSM-VIRTUALMIN"


class IntegrityError(VSMError):
    code = "VSM-INTEGRITY"


class UpgradeError(VSMError):
    code = "VSM-UPGRADE"
