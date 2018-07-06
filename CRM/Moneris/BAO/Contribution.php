<?php

class CRM_Moneris_BAO_Contribution extends CRM_Contribute_BAO_Contribution {

  // Hack to use the protected CRM_Contribute_BAO_Contribution::updateMembershipBasedOnCompletionOfContribution
  public static function customUpdateMembershipBasedOnCompletionOfContribution($contribution, $primaryContributionID, $changeDate, $contributionStatus = 'Completed') {

    if (version_compare(\CRM_Utils_System::version(), '5.3.0', '>')) {
      static::updateMembershipBasedOnCompletionOfContribution($contribution, $primaryContributionID, $changeDate, $contributionStatus);
    }
    else {
      $membershipIDs = array();
      $contribution->loadRelatedMembershipObjects($membershipIDs);
      $memberships = $contribution->_relatedObjects['membership'];
      if (!empty($memberships)) {
        static::updateMembershipBasedOnCompletionOfContribution($contribution, $memberships, $primaryContributionID, $changeDate, $contributionStatus);
      }
    }
  }

}

