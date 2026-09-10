const merge = require('lodash.merge');
const { z } = require('zod');

const ProfileSchema = z.object({ name: z.string() });

function updateProfile(profile, params) {
  const validated = ProfileSchema.parse(params);
  return merge({}, profile, validated);
}

module.exports = { updateProfile };
