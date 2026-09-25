const merge = require('lodash.merge');

function updateProfile(profile, params) {
  return merge({}, profile, params);
}

module.exports = { updateProfile };
