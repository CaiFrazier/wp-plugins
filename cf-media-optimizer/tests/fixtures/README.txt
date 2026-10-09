Synthetic color-management regression fixtures; no customer photography.
srgb-reference.png is a generated 96x64 RGB gradient.
prophoto.jpg and prophoto.png encode that gradient through a generated RGB
profile with ProPhoto primaries, D50 white point, and gamma 1.8. The profile
was generated with Little CMS 2 cmsCreateRGBProfile(), not copied from a photo.
The converter output must reproduce the reference appearance, not preserve
the source's unconverted numeric RGB values after discarding its ICC profile.
