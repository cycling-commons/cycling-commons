<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->

# The Earth is awkward

Somewhere in Wallonia, a little east of Spa, there is a drinking-water fountain. It is an ordinary
physical object. You can ride up to it, fill a bottle, and carry on. It sits in exactly one place,
and that place does not move.

Somebody wants to put it on a map. So somebody has to write its position down.

That is where every problem in this series starts. The fountain itself is simple; turning "where it
is" into numbers a computer can store, compare, and draw is not. The moment a position becomes
numbers, the shape of the planet starts leaking into your code, and it keeps leaking for the next
nine chapters. This one covers the two numbers themselves: what they mean, how they behave, and why
they do not behave like the coordinates you already know.

## Latitude and longitude

A position on the Earth's surface is normally written as two angles: one measured north or south of
the equator, the other east or west of a reference meridian.

**Latitude** is how far north or south you are. It runs from −90 at the South Pole, through 0 at the
**equator**, the circle exactly halfway between the poles, to +90 at the North Pole. A line
joining every point with the same latitude is called a **parallel**, because those lines never meet:
they are a stack of circles, each one smaller than the one below it, shrinking to nothing at each
pole.

<figure class="gis-fig">
<svg viewBox="0 0 640 392" role="img" aria-labelledby="f20-t f20-d" xmlns="http://www.w3.org/2000/svg"><title id="f20-t">Parallels: a stack of circles that never meet</title><desc id="f20-d">On the left, a globe tilted so a little of the northern hemisphere shows from above. Eleven circles of constant latitude are drawn around it, one every fifteen degrees, solid where they cross the near side and faintly dashed where they continue round the back. The circle at zero degrees, the equator, is the widest and is drawn in the accent colour, with a short tick and a zero at its western end. Every circle above and below it is smaller than the one before, and they shrink toward each pole without ever touching. The poles at plus ninety and minus ninety are single dots, because a parallel there has closed to a point. On the right, the same idea in words: circles of constant latitude every fifteen degrees; they stack, they shrink and they never meet; at each pole the last one closes to a point; and zero is not arbitrary, because the equator is real.</desc><circle class="gis-muted" cx="174" cy="185" r="118" fill="none"/><path class="gis-ink" d="M 174.0 67.9 L 171.9 67.9 L 169.7 68.0 L 167.7 68.1 L 165.6 68.3 L 163.6 68.6 L 161.6 68.9 L 159.7 69.2 L 157.8 69.6 L 156.0 70.1 L 154.4 70.6 L 152.8 71.1 L 151.3 71.7 L 149.9 72.3 L 148.7 72.9 L 147.6 73.6 L 146.6 74.3 L 145.7 75.0 L 145.0 75.8 L 144.4 76.6 L 143.9 77.3 L 143.6 78.1 L 143.5 78.9 L 143.5 79.7 L 143.6 80.5 L 143.9 81.3 L 144.4 82.1 L 145.0 82.9 L 145.7 83.6 L 146.6 84.3 L 147.6 85.0 L 148.7 85.7 L 149.9 86.4 L 151.3 87.0 L 152.8 87.6 L 154.4 88.1 L 156.0 88.6 L 157.8 89.0 L 159.7 89.4 L 161.6 89.8 L 163.6 90.1 L 165.6 90.3 L 167.7 90.5 L 169.7 90.6 L 171.9 90.7 L 174.0 90.8 L 176.1 90.7 L 178.3 90.6 L 180.3 90.5 L 182.4 90.3 L 184.4 90.1 L 186.4 89.8 L 188.3 89.4 L 190.2 89.0 L 192.0 88.6 L 193.6 88.1 L 195.2 87.6 L 196.7 87.0 L 198.1 86.4 L 199.3 85.7 L 200.4 85.0 L 201.4 84.3 L 202.3 83.6 L 203.0 82.9 L 203.6 82.1 L 204.1 81.3 L 204.4 80.5 L 204.5 79.7 L 204.5 78.9 L 204.4 78.1 L 204.1 77.3 L 203.6 76.6 L 203.0 75.8 L 202.3 75.0 L 201.4 74.3 L 200.4 73.6 L 199.3 72.9 L 198.1 72.3 L 196.7 71.7 L 195.2 71.1 L 193.6 70.6 L 192.0 70.1 L 190.2 69.6 L 188.3 69.2 L 186.4 68.9 L 184.4 68.6 L 182.4 68.3 L 180.3 68.1 L 178.3 68.0 L 176.1 67.9 L 174.0 67.9" fill="none" stroke-width="1"/><path class="gis-muted" stroke-dasharray="3 4" d="M 174.0 68.1 L 169.9 68.2 L 165.8 68.4 L 161.7 68.6 L 157.7 69.0 L 153.8 69.5 L 150.0 70.1 L 146.3 70.7 L 142.7 71.5 L 139.3 72.4 L 136.1 73.3 L 133.0 74.4" fill="none" stroke-width="0.8"/><path class="gis-ink" d="M 133.0 74.4 L 130.2 75.5 L 127.5 76.6 L 125.1 77.9 L 122.9 79.2 L 121.0 80.6 L 119.3 82.0 L 117.9 83.4 L 116.8 84.9 L 115.9 86.4 L 115.3 87.9 L 115.0 89.5 L 115.0 91.0 L 115.3 92.6 L 115.9 94.1 L 116.8 95.6 L 117.9 97.1 L 119.3 98.5 L 121.0 99.9 L 122.9 101.3 L 125.1 102.6 L 127.5 103.9 L 130.2 105.0 L 133.0 106.1 L 136.1 107.2 L 139.3 108.1 L 142.7 109.0 L 146.3 109.8 L 150.0 110.4 L 153.8 111.0 L 157.7 111.5 L 161.7 111.9 L 165.8 112.1 L 169.9 112.3 L 174.0 112.4 L 178.1 112.3 L 182.2 112.1 L 186.3 111.9 L 190.3 111.5 L 194.2 111.0 L 198.0 110.4 L 201.7 109.8 L 205.3 109.0 L 208.7 108.1 L 211.9 107.2 L 215.0 106.1 L 217.8 105.0 L 220.5 103.9 L 222.9 102.6 L 225.1 101.3 L 227.0 99.9 L 228.7 98.5 L 230.1 97.1 L 231.2 95.6 L 232.1 94.1 L 232.7 92.6 L 233.0 91.0 L 233.0 89.5 L 232.7 87.9 L 232.1 86.4 L 231.2 84.9 L 230.1 83.4 L 228.7 82.0 L 227.0 80.6 L 225.1 79.2 L 222.9 77.9 L 220.5 76.6 L 217.8 75.5" fill="none" stroke-width="1"/><path class="gis-muted" stroke-dasharray="3 4" d="M 217.8 75.5 L 215.0 74.4 L 211.9 73.3 L 208.7 72.4 L 205.3 71.5 L 201.7 70.7 L 198.0 70.1 L 194.2 69.5 L 190.3 69.0 L 186.3 68.6 L 182.2 68.4 L 178.1 68.2 L 174.0 68.1" fill="none" stroke-width="0.8"/><path class="gis-muted" stroke-dasharray="3 4" d="M 174.0 76.4 L 168.2 76.5 L 162.4 76.7 L 156.7 77.1 L 151.0 77.6 L 145.5 78.3 L 140.1 79.1 L 134.8 80.0 L 129.8 81.1 L 125.0 82.3 L 120.4 83.7 L 116.0 85.2 L 112.0 86.7 L 108.2 88.4 L 104.8 90.2 L 101.7 92.0 L 99.0 93.9" fill="none" stroke-width="0.8"/><path class="gis-ink" d="M 99.0 93.9 L 96.6 95.9 L 94.6 98.0 L 93.0 100.1 L 91.8 102.2 L 91.0 104.4 L 90.6 106.5 L 90.6 108.7 L 91.0 110.9 L 91.8 113.1 L 93.0 115.2 L 94.6 117.3 L 96.6 119.3 L 99.0 121.3 L 101.7 123.3 L 104.8 125.1 L 108.2 126.9 L 112.0 128.6 L 116.0 130.1 L 120.4 131.6 L 125.0 132.9 L 129.8 134.1 L 134.8 135.2 L 140.1 136.2 L 145.5 137.0 L 151.0 137.7 L 156.7 138.2 L 162.4 138.6 L 168.2 138.8 L 174.0 138.9 L 179.8 138.8 L 185.6 138.6 L 191.3 138.2 L 197.0 137.7 L 202.5 137.0 L 207.9 136.2 L 213.2 135.2 L 218.2 134.1 L 223.0 132.9 L 227.6 131.6 L 232.0 130.1 L 236.0 128.6 L 239.8 126.9 L 243.2 125.1 L 246.3 123.3 L 249.0 121.3 L 251.4 119.3 L 253.4 117.3 L 255.0 115.2 L 256.2 113.1 L 257.0 110.9 L 257.4 108.7 L 257.4 106.5 L 257.0 104.4 L 256.2 102.2 L 255.0 100.1 L 253.4 98.0 L 251.4 95.9" fill="none" stroke-width="1"/><path class="gis-muted" stroke-dasharray="3 4" d="M 251.4 95.9 L 249.0 93.9 L 246.3 92.0 L 243.2 90.2 L 239.8 88.4 L 236.0 86.7 L 232.0 85.2 L 227.6 83.7 L 223.0 82.3 L 218.2 81.1 L 213.2 80.0 L 207.9 79.1 L 202.5 78.3 L 197.0 77.6 L 191.3 77.1 L 185.6 76.7 L 179.8 76.5 L 174.0 76.4" fill="none" stroke-width="0.8"/><path class="gis-muted" stroke-dasharray="3 4" d="M 174.0 92.0 L 166.9 92.1 L 159.8 92.4 L 152.8 92.9 L 145.8 93.5 L 139.0 94.3 L 132.4 95.3 L 126.0 96.5 L 119.8 97.8 L 113.9 99.3 L 108.3 101.0 L 103.0 102.8 L 98.1 104.7 L 93.5 106.7 L 89.3 108.9 L 85.5 111.2 L 82.2 113.5 L 79.3 116.0 L 76.8 118.5 L 74.8 121.0" fill="none" stroke-width="0.8"/><path class="gis-ink" d="M 74.8 121.0 L 73.4 123.6 L 72.4 126.3 L 71.9 129.0 L 71.9 131.6 L 72.4 134.3 L 73.4 136.9 L 74.8 139.6 L 76.8 142.1 L 79.3 144.6 L 82.2 147.1 L 85.5 149.4 L 89.3 151.7 L 93.5 153.9 L 98.1 155.9 L 103.0 157.8 L 108.3 159.6 L 113.9 161.3 L 119.8 162.8 L 126.0 164.1 L 132.4 165.3 L 139.0 166.3 L 145.8 167.1 L 152.8 167.7 L 159.8 168.2 L 166.9 168.5 L 174.0 168.6 L 181.1 168.5 L 188.2 168.2 L 195.2 167.7 L 202.2 167.1 L 209.0 166.3 L 215.6 165.3 L 222.0 164.1 L 228.2 162.8 L 234.1 161.3 L 239.7 159.6 L 245.0 157.8 L 249.9 155.9 L 254.5 153.9 L 258.7 151.7 L 262.5 149.4 L 265.8 147.1 L 268.7 144.6 L 271.2 142.1 L 273.2 139.6 L 274.6 136.9 L 275.6 134.3 L 276.1 131.6 L 276.1 129.0 L 275.6 126.3 L 274.6 123.6" fill="none" stroke-width="1"/><path class="gis-muted" stroke-dasharray="3 4" d="M 274.6 123.6 L 273.2 121.0 L 271.2 118.5 L 268.7 116.0 L 265.8 113.5 L 262.5 111.2 L 258.7 108.9 L 254.5 106.7 L 249.9 104.7 L 245.0 102.8 L 239.7 101.0 L 234.1 99.3 L 228.2 97.8 L 222.0 96.5 L 215.6 95.3 L 209.0 94.3 L 202.2 93.5 L 195.2 92.9 L 188.2 92.4 L 181.1 92.1 L 174.0 92.0" fill="none" stroke-width="0.8"/><path class="gis-muted" stroke-dasharray="3 4" d="M 174.0 114.0 L 166.0 114.1 L 158.1 114.4 L 150.3 114.9 L 142.6 115.6 L 135.0 116.6 L 127.6 117.7 L 120.5 119.0 L 113.6 120.5 L 107.0 122.1 L 100.7 124.0 L 94.8 126.0 L 89.3 128.1 L 84.2 130.4 L 79.5 132.8 L 75.3 135.3 L 71.6 138.0 L 68.3 140.7 L 65.6 143.5 L 63.4 146.4 L 61.8 149.3" fill="none" stroke-width="0.8"/><path class="gis-ink" d="M 61.8 149.3 L 60.6 152.2 L 60.1 155.2 L 60.1 158.2 L 60.6 161.1 L 61.8 164.1 L 63.4 167.0 L 65.6 169.9 L 68.3 172.7 L 71.6 175.4 L 75.3 178.0 L 79.5 180.6 L 84.2 183.0 L 89.3 185.3 L 94.8 187.4 L 100.7 189.4 L 107.0 191.2 L 113.6 192.9 L 120.5 194.4 L 127.6 195.7 L 135.0 196.8 L 142.6 197.7 L 150.3 198.4 L 158.1 199.0 L 166.0 199.3 L 174.0 199.4 L 182.0 199.3 L 189.9 199.0 L 197.7 198.4 L 205.4 197.7 L 213.0 196.8 L 220.4 195.7 L 227.5 194.4 L 234.4 192.9 L 241.0 191.2 L 247.3 189.4 L 253.2 187.4 L 258.7 185.3 L 263.8 183.0 L 268.5 180.6 L 272.7 178.0 L 276.4 175.4 L 279.7 172.7 L 282.4 169.9 L 284.6 167.0 L 286.2 164.1 L 287.4 161.1 L 287.9 158.2 L 287.9 155.2 L 287.4 152.2" fill="none" stroke-width="1"/><path class="gis-muted" stroke-dasharray="3 4" d="M 287.4 152.2 L 286.2 149.3 L 284.6 146.4 L 282.4 143.5 L 279.7 140.7 L 276.4 138.0 L 272.7 135.3 L 268.5 132.8 L 263.8 130.4 L 258.7 128.1 L 253.2 126.0 L 247.3 124.0 L 241.0 122.1 L 234.4 120.5 L 227.5 119.0 L 220.4 117.7 L 213.0 116.6 L 205.4 115.6 L 197.7 114.9 L 189.9 114.4 L 182.0 114.1 L 174.0 114.0" fill="none" stroke-width="0.8"/><path class="gis-muted" stroke-dasharray="3 4" d="M 174.0 140.8 L 165.8 140.9 L 157.6 141.2 L 149.5 141.8 L 141.5 142.5 L 133.6 143.5 L 126.0 144.6 L 118.6 146.0 L 111.5 147.5 L 104.6 149.2 L 98.2 151.1 L 92.0 153.2 L 86.3 155.4 L 81.0 157.8 L 76.2 160.3 L 71.8 162.9 L 67.9 165.6 L 64.6 168.4 L 61.8 171.3 L 59.5 174.3 L 57.8 177.3 L 56.6 180.4 L 56.1 183.5" fill="none" stroke-width="0.8"/><path class="gis-accent" d="M 56.1 183.5 L 56.1 186.5 L 56.6 189.6 L 57.8 192.7 L 59.5 195.7 L 61.8 198.7 L 64.6 201.6 L 67.9 204.4 L 71.8 207.1 L 76.2 209.7 L 81.0 212.2 L 86.3 214.6 L 92.0 216.8 L 98.2 218.9 L 104.6 220.8 L 111.5 222.5 L 118.6 224.0 L 126.0 225.4 L 133.6 226.5 L 141.5 227.5 L 149.5 228.2 L 157.6 228.8 L 165.8 229.1 L 174.0 229.2 L 182.2 229.1 L 190.4 228.8 L 198.5 228.2 L 206.5 227.5 L 214.4 226.5 L 222.0 225.4 L 229.4 224.0 L 236.5 222.5 L 243.4 220.8 L 249.8 218.9 L 256.0 216.8 L 261.7 214.6 L 267.0 212.2 L 271.8 209.7 L 276.2 207.1 L 280.1 204.4 L 283.4 201.6 L 286.2 198.7 L 288.5 195.7 L 290.2 192.7 L 291.4 189.6 L 291.9 186.5" fill="none"/><path class="gis-muted" stroke-dasharray="3 4" d="M 291.9 186.5 L 291.9 183.5 L 291.4 180.4 L 290.2 177.3 L 288.5 174.3 L 286.2 171.3 L 283.4 168.4 L 280.1 165.6 L 276.2 162.9 L 271.8 160.3 L 267.0 157.8 L 261.7 155.4 L 256.0 153.2 L 249.8 151.1 L 243.4 149.2 L 236.5 147.5 L 229.4 146.0 L 222.0 144.6 L 214.4 143.5 L 206.5 142.5 L 198.5 141.8 L 190.4 141.2 L 182.2 140.9 L 174.0 140.8" fill="none" stroke-width="0.8"/><path class="gis-muted" stroke-dasharray="3 4" d="M 174.0 170.6 L 166.0 170.7 L 158.1 171.0 L 150.3 171.6 L 142.6 172.3 L 135.0 173.2 L 127.6 174.3 L 120.5 175.6 L 113.6 177.1 L 107.0 178.8 L 100.7 180.6 L 94.8 182.6 L 89.3 184.7 L 84.2 187.0 L 79.5 189.4 L 75.3 192.0 L 71.6 194.6 L 68.3 197.3 L 65.6 200.1 L 63.4 203.0 L 61.8 205.9 L 60.6 208.9 L 60.1 211.8 L 60.1 214.8 L 60.6 217.8" fill="none" stroke-width="0.8"/><path class="gis-ink" d="M 60.6 217.8 L 61.8 220.7 L 63.4 223.6 L 65.6 226.5 L 68.3 229.3 L 71.6 232.0 L 75.3 234.7 L 79.5 237.2 L 84.2 239.6 L 89.3 241.9 L 94.8 244.0 L 100.7 246.0 L 107.0 247.9 L 113.6 249.5 L 120.5 251.0 L 127.6 252.3 L 135.0 253.4 L 142.6 254.4 L 150.3 255.1 L 158.1 255.6 L 166.0 255.9 L 174.0 256.0 L 182.0 255.9 L 189.9 255.6 L 197.7 255.1 L 205.4 254.4 L 213.0 253.4 L 220.4 252.3 L 227.5 251.0 L 234.4 249.5 L 241.0 247.9 L 247.3 246.0 L 253.2 244.0 L 258.7 241.9 L 263.8 239.6 L 268.5 237.2 L 272.7 234.7 L 276.4 232.0 L 279.7 229.3 L 282.4 226.5 L 284.6 223.6 L 286.2 220.7" fill="none" stroke-width="1"/><path class="gis-muted" stroke-dasharray="3 4" d="M 286.2 220.7 L 287.4 217.8 L 287.9 214.8 L 287.9 211.8 L 287.4 208.9 L 286.2 205.9 L 284.6 203.0 L 282.4 200.1 L 279.7 197.3 L 276.4 194.6 L 272.7 192.0 L 268.5 189.4 L 263.8 187.0 L 258.7 184.7 L 253.2 182.6 L 247.3 180.6 L 241.0 178.8 L 234.4 177.1 L 227.5 175.6 L 220.4 174.3 L 213.0 173.2 L 205.4 172.3 L 197.7 171.6 L 189.9 171.0 L 182.0 170.7 L 174.0 170.6" fill="none" stroke-width="0.8"/><path class="gis-muted" stroke-dasharray="3 4" d="M 174.0 201.4 L 166.9 201.5 L 159.8 201.8 L 152.8 202.3 L 145.8 202.9 L 139.0 203.7 L 132.4 204.7 L 126.0 205.9 L 119.8 207.2 L 113.9 208.7 L 108.3 210.4 L 103.0 212.2 L 98.1 214.1 L 93.5 216.1 L 89.3 218.3 L 85.5 220.6 L 82.2 222.9 L 79.3 225.4 L 76.8 227.9 L 74.8 230.4 L 73.4 233.1 L 72.4 235.7 L 71.9 238.4 L 71.9 241.0 L 72.4 243.7 L 73.4 246.4" fill="none" stroke-width="0.8"/><path class="gis-ink" d="M 73.4 246.4 L 74.8 249.0 L 76.8 251.5 L 79.3 254.0 L 82.2 256.5 L 85.5 258.8 L 89.3 261.1 L 93.5 263.3 L 98.1 265.3 L 103.0 267.2 L 108.3 269.0 L 113.9 270.7 L 119.8 272.2 L 126.0 273.5 L 132.4 274.7 L 139.0 275.7 L 145.8 276.5 L 152.8 277.1 L 159.8 277.6 L 166.9 277.9 L 174.0 278.0 L 181.1 277.9 L 188.2 277.6 L 195.2 277.1 L 202.2 276.5 L 209.0 275.7 L 215.6 274.7 L 222.0 273.5 L 228.2 272.2 L 234.1 270.7 L 239.7 269.0 L 245.0 267.2 L 249.9 265.3 L 254.5 263.3 L 258.7 261.1 L 262.5 258.8 L 265.8 256.5 L 268.7 254.0 L 271.2 251.5 L 273.2 249.0" fill="none" stroke-width="1"/><path class="gis-muted" stroke-dasharray="3 4" d="M 273.2 249.0 L 274.6 246.4 L 275.6 243.7 L 276.1 241.0 L 276.1 238.4 L 275.6 235.7 L 274.6 233.1 L 273.2 230.4 L 271.2 227.9 L 268.7 225.4 L 265.8 222.9 L 262.5 220.6 L 258.7 218.3 L 254.5 216.1 L 249.9 214.1 L 245.0 212.2 L 239.7 210.4 L 234.1 208.7 L 228.2 207.2 L 222.0 205.9 L 215.6 204.7 L 209.0 203.7 L 202.2 202.9 L 195.2 202.3 L 188.2 201.8 L 181.1 201.5 L 174.0 201.4" fill="none" stroke-width="0.8"/><path class="gis-muted" stroke-dasharray="3 4" d="M 174.0 231.1 L 168.2 231.2 L 162.4 231.4 L 156.7 231.8 L 151.0 232.3 L 145.5 233.0 L 140.1 233.8 L 134.8 234.8 L 129.8 235.9 L 125.0 237.1 L 120.4 238.4 L 116.0 239.9 L 112.0 241.4 L 108.2 243.1 L 104.8 244.9 L 101.7 246.7 L 99.0 248.7 L 96.6 250.7 L 94.6 252.7 L 93.0 254.8 L 91.8 256.9 L 91.0 259.1 L 90.6 261.3 L 90.6 263.5 L 91.0 265.6 L 91.8 267.8 L 93.0 269.9 L 94.6 272.0 L 96.6 274.1" fill="none" stroke-width="0.8"/><path class="gis-ink" d="M 96.6 274.1 L 99.0 276.1 L 101.7 278.0 L 104.8 279.8 L 108.2 281.6 L 112.0 283.3 L 116.0 284.8 L 120.4 286.3 L 125.0 287.7 L 129.8 288.9 L 134.8 290.0 L 140.1 290.9 L 145.5 291.7 L 151.0 292.4 L 156.7 292.9 L 162.4 293.3 L 168.2 293.5 L 174.0 293.6 L 179.8 293.5 L 185.6 293.3 L 191.3 292.9 L 197.0 292.4 L 202.5 291.7 L 207.9 290.9 L 213.2 290.0 L 218.2 288.9 L 223.0 287.7 L 227.6 286.3 L 232.0 284.8 L 236.0 283.3 L 239.8 281.6 L 243.2 279.8 L 246.3 278.0 L 249.0 276.1" fill="none" stroke-width="1"/><path class="gis-muted" stroke-dasharray="3 4" d="M 249.0 276.1 L 251.4 274.1 L 253.4 272.0 L 255.0 269.9 L 256.2 267.8 L 257.0 265.6 L 257.4 263.5 L 257.4 261.3 L 257.0 259.1 L 256.2 256.9 L 255.0 254.8 L 253.4 252.7 L 251.4 250.7 L 249.0 248.7 L 246.3 246.7 L 243.2 244.9 L 239.8 243.1 L 236.0 241.4 L 232.0 239.9 L 227.6 238.4 L 223.0 237.1 L 218.2 235.9 L 213.2 234.8 L 207.9 233.8 L 202.5 233.0 L 197.0 232.3 L 191.3 231.8 L 185.6 231.4 L 179.8 231.2 L 174.0 231.1" fill="none" stroke-width="0.8"/><path class="gis-muted" stroke-dasharray="3 4" d="M 174.0 257.6 L 169.9 257.7 L 165.8 257.9 L 161.7 258.1 L 157.7 258.5 L 153.8 259.0 L 150.0 259.6 L 146.3 260.2 L 142.7 261.0 L 139.3 261.9 L 136.1 262.8 L 133.0 263.9 L 130.2 265.0 L 127.5 266.1 L 125.1 267.4 L 122.9 268.7 L 121.0 270.1 L 119.3 271.5 L 117.9 272.9 L 116.8 274.4 L 115.9 275.9 L 115.3 277.4 L 115.0 279.0 L 115.0 280.5 L 115.3 282.1 L 115.9 283.6 L 116.8 285.1 L 117.9 286.6 L 119.3 288.0 L 121.0 289.4 L 122.9 290.8 L 125.1 292.1 L 127.5 293.4 L 130.2 294.5" fill="none" stroke-width="0.8"/><path class="gis-ink" d="M 130.2 294.5 L 133.0 295.6 L 136.1 296.7 L 139.3 297.6 L 142.7 298.5 L 146.3 299.3 L 150.0 299.9 L 153.8 300.5 L 157.7 301.0 L 161.7 301.4 L 165.8 301.6 L 169.9 301.8 L 174.0 301.9 L 178.1 301.8 L 182.2 301.6 L 186.3 301.4 L 190.3 301.0 L 194.2 300.5 L 198.0 299.9 L 201.7 299.3 L 205.3 298.5 L 208.7 297.6 L 211.9 296.7 L 215.0 295.6" fill="none" stroke-width="1"/><path class="gis-muted" stroke-dasharray="3 4" d="M 215.0 295.6 L 217.8 294.5 L 220.5 293.4 L 222.9 292.1 L 225.1 290.8 L 227.0 289.4 L 228.7 288.0 L 230.1 286.6 L 231.2 285.1 L 232.1 283.6 L 232.7 282.1 L 233.0 280.5 L 233.0 279.0 L 232.7 277.4 L 232.1 275.9 L 231.2 274.4 L 230.1 272.9 L 228.7 271.5 L 227.0 270.1 L 225.1 268.7 L 222.9 267.4 L 220.5 266.1 L 217.8 265.0 L 215.0 263.9 L 211.9 262.8 L 208.7 261.9 L 205.3 261.0 L 201.7 260.2 L 198.0 259.6 L 194.2 259.0 L 190.3 258.5 L 186.3 258.1 L 182.2 257.9 L 178.1 257.7 L 174.0 257.6" fill="none" stroke-width="0.8"/><path class="gis-muted" stroke-dasharray="3 4" d="M 174.0 279.2 L 171.9 279.3 L 169.7 279.4 L 167.7 279.5 L 165.6 279.7 L 163.6 279.9 L 161.6 280.2 L 159.7 280.6 L 157.8 281.0 L 156.0 281.4 L 154.4 281.9 L 152.8 282.4 L 151.3 283.0 L 149.9 283.6 L 148.7 284.3 L 147.6 285.0 L 146.6 285.7 L 145.7 286.4 L 145.0 287.1 L 144.4 287.9 L 143.9 288.7 L 143.6 289.5 L 143.5 290.3 L 143.5 291.1 L 143.6 291.9 L 143.9 292.7 L 144.4 293.4 L 145.0 294.2 L 145.7 295.0 L 146.6 295.7 L 147.6 296.4 L 148.7 297.1 L 149.9 297.7 L 151.3 298.3 L 152.8 298.9 L 154.4 299.4 L 156.0 299.9 L 157.8 300.4 L 159.7 300.8 L 161.6 301.1 L 163.6 301.4 L 165.6 301.7 L 167.7 301.9 L 169.7 302.0 L 171.9 302.1 L 174.0 302.1 L 176.1 302.1 L 178.3 302.0 L 180.3 301.9 L 182.4 301.7 L 184.4 301.4 L 186.4 301.1 L 188.3 300.8 L 190.2 300.4 L 192.0 299.9 L 193.6 299.4 L 195.2 298.9 L 196.7 298.3 L 198.1 297.7 L 199.3 297.1 L 200.4 296.4 L 201.4 295.7 L 202.3 295.0 L 203.0 294.2 L 203.6 293.4 L 204.1 292.7 L 204.4 291.9 L 204.5 291.1 L 204.5 290.3 L 204.4 289.5 L 204.1 288.7 L 203.6 287.9 L 203.0 287.1 L 202.3 286.4 L 201.4 285.7 L 200.4 285.0 L 199.3 284.3 L 198.1 283.6 L 196.7 283.0 L 195.2 282.4 L 193.6 281.9 L 192.0 281.4 L 190.2 281.0 L 188.3 280.6 L 186.4 280.2 L 184.4 279.9 L 182.4 279.7 L 180.3 279.5 L 178.3 279.4 L 176.1 279.3 L 174.0 279.2" fill="none" stroke-width="0.8"/><circle class="gis-ink gis-fill-ink" cx="174" cy="76" r="4"/><text class="gis-label-md gis-halo" x="174" y="62" text-anchor="middle">+90</text><circle class="gis-ink gis-fill-ink" cx="174" cy="294" r="4"/><text class="gis-label-md gis-halo" x="174" y="324" text-anchor="middle">−90</text><line class="gis-accent" x1="38" y1="183" x2="56" y2="183"/><text class="gis-label-md" x="32" y="190" text-anchor="end">0</text><text class="gis-label-mono" x="340" y="72">Parallels</text><text class="gis-label-md" x="340" y="114">Circles of constant</text><text class="gis-label-md" x="340" y="142">latitude, every 15°.</text><text class="gis-label-md" x="340" y="184">They stack, they shrink,</text><text class="gis-label-md" x="340" y="212">and they never meet.</text><text class="gis-label-md" x="340" y="254">At each pole the last</text><text class="gis-label-md" x="340" y="282">one closes to a point.</text><text class="gis-label-md" x="340" y="324">Zero is not arbitrary:</text><text class="gis-label-md" x="340" y="352">the equator is real.</text></svg>
<figcaption>Because parallels never meet, one degree of latitude buys the same ground everywhere:
about 111 km in Belgium, in Kenya and in Antarctica alike. It is the only one of the two angles you
can turn into a distance without asking a second question.</figcaption>
</figure>

**Longitude** is how far east or west you are. It runs from −180 to +180, with 0 at the **prime
meridian**, an arbitrary line through Greenwich in London that everybody agreed to use. A line
joining every point with the same longitude is called a **meridian**. Unlike parallels, meridians
are not parallel at all. Every one of them is half of a full circle running from the North Pole to
the South Pole, so all 360 of them meet at both ends.

<figure class="gis-fig">
<svg viewBox="0 0 640 470" role="img" aria-labelledby="f21-t f21-d" xmlns="http://www.w3.org/2000/svg"><title id="f21-t">Meridians: half-circles that all meet at both poles</title><desc id="f21-d">On the left, the same tilted globe drawn with twelve meridians, one every thirty degrees of longitude, each half of a full circle running pole to pole, solid across the near side and faintly dashed round the back. Unlike parallels these are not parallel at all: they are widest apart at the equator and converge until every one passes through the same two points. Those points are marked with filled dots and labelled all three hundred and sixty meet here, and here. The meridian through Greenwich is drawn in the accent colour. On the right, the same idea in words: half-circles of constant longitude every thirty degrees; not parallel at all, widest at the equator, converging until all three hundred and sixty pass through two points; and zero is an agreement, Greenwich by consent rather than by any fact about the planet. Beneath the globe a small inset shows those meridians seen from straight above the pole, where they appear as spokes radiating from one centre rather than as rails running side by side.</desc><circle class="gis-muted" cx="160" cy="178" r="132" fill="none"/><path class="gis-muted" stroke-dasharray="3 4" d="M 160.0 300.4 L 160.0 296.6 L 160.0 292.3 L 160.0 287.4 L 160.0 282.0 L 160.0 276.1 L 160.0 269.7 L 160.0 262.8 L 160.0 255.6 L 160.0 247.9 L 160.0 240.0 L 160.0 231.7 L 160.0 223.1 L 160.0 214.4 L 160.0 205.4 L 160.0 196.4 L 160.0 187.2 L 160.0 178.0 L 160.0 168.8 L 160.0 159.6 L 160.0 150.6 L 160.0 141.6 L 160.0 132.9 L 160.0 124.3 L 160.0 116.0 L 160.0 108.1 L 160.0 100.4 L 160.0 93.2 L 160.0 86.3 L 160.0 79.9 L 160.0 74.0 L 160.0 68.6 L 160.0 63.7 L 160.0 59.4 L 160.0 55.6 L 160.0 52.5 L 160.0 49.9 L 160.0 48.0 L 160.0 46.7 L 160.0 46.1" fill="none" stroke-width="0.8"/><path class="gis-ink" d="M 160.0 46.1 L 160.0 46.1 L 160.0 46.7 L 160.0 48.0 L 160.0 49.9 L 160.0 52.5 L 160.0 55.6" fill="none" stroke-width="1"/><path class="gis-muted" stroke-dasharray="3 4" d="M 160.0 300.4 L 155.4 297.1 L 150.8 293.2 L 146.3 288.8 L 141.8 283.8 L 137.4 278.4 L 133.2 272.4 L 129.0 266.0 L 125.0 259.1 L 121.2 251.8 L 117.6 244.2 L 114.2 236.3 L 111.0 228.1 L 108.0 219.6 L 105.3 210.9 L 102.8 202.1 L 100.7 193.2 L 98.8 184.1 L 97.2 175.1 L 96.0 166.1 L 95.0 157.1 L 94.4 148.2 L 94.0 139.5 L 94.0 130.9 L 94.4 122.6 L 95.0 114.6 L 96.0 106.8 L 97.2 99.5 L 98.8 92.4 L 100.7 85.9 L 102.8 79.7 L 105.3 74.1 L 108.0 68.9 L 111.0 64.3 L 114.2 60.2 L 117.6 56.7 L 121.2 53.8 L 125.0 51.5 L 129.0 49.8" fill="none" stroke-width="0.8"/><path class="gis-ink" d="M 129.0 49.8 L 133.2 48.8 L 137.4 48.3 L 141.8 48.5 L 146.3 49.4 L 150.8 50.8 L 155.4 52.9 L 160.0 55.6" fill="none" stroke-width="1"/><path class="gis-muted" stroke-dasharray="3 4" d="M 160.0 300.4 L 152.0 298.4 L 144.1 295.8 L 136.2 292.6 L 128.5 288.8 L 120.9 284.6 L 113.5 279.8 L 106.3 274.5 L 99.4 268.7 L 92.8 262.5 L 86.5 255.9 L 80.6 248.9 L 75.0 241.5 L 69.9 233.9 L 65.2 225.9 L 61.0 217.8 L 57.3 209.4 L 54.0 200.9 L 51.3 192.3 L 49.1 183.6 L 47.4 174.9 L 46.3 166.2 L 45.8 157.6 L 45.8 149.0 L 46.3 140.6 L 47.4 132.4 L 49.1 124.4 L 51.3 116.7 L 54.0 109.2 L 57.3 102.1 L 61.0 95.4 L 65.2 89.1 L 69.9 83.2 L 75.0 77.7 L 80.6 72.8 L 86.5 68.4" fill="none" stroke-width="0.8"/><path class="gis-ink" d="M 86.5 68.4 L 92.8 64.5 L 99.4 61.1 L 106.3 58.3 L 113.5 56.1 L 120.9 54.5 L 128.5 53.5 L 136.2 53.1 L 144.1 53.4 L 152.0 54.2 L 160.0 55.6" fill="none" stroke-width="1"/><path class="gis-muted" stroke-dasharray="3 4" d="M 160.0 300.4 L 150.8 300.1 L 141.6 299.2 L 132.6 297.7 L 123.6 295.6 L 114.9 293.0 L 106.3 289.8 L 98.0 286.1 L 90.1 281.8 L 82.4 277.0 L 75.2 271.8 L 68.3 266.0 L 61.9 259.9 L 56.0 253.3 L 50.6 246.4 L 45.7 239.2 L 41.4 231.7 L 37.6 223.8 L 34.5 215.8 L 31.9 207.6 L 30.0 199.3 L 28.7 190.8 L 28.1 182.3" fill="none" stroke-width="0.8"/><path class="gis-ink" d="M 28.1 182.3 L 28.1 173.7 L 28.7 165.2 L 30.0 156.7 L 31.9 148.4 L 34.5 140.2 L 37.6 132.2 L 41.4 124.3 L 45.7 116.8 L 50.6 109.6 L 56.0 102.7 L 61.9 96.1 L 68.3 90.0 L 75.2 84.2 L 82.4 79.0 L 90.1 74.2 L 98.0 69.9 L 106.3 66.2 L 114.9 63.0 L 123.6 60.4 L 132.6 58.3 L 141.6 56.8 L 150.8 55.9 L 160.0 55.6" fill="none" stroke-width="1"/><path class="gis-muted" stroke-dasharray="3 4" d="M 160.0 300.4 L 152.0 301.8 L 144.1 302.6 L 136.2 302.9 L 128.5 302.5 L 120.9 301.5 L 113.5 299.9 L 106.3 297.7 L 99.4 294.9 L 92.8 291.5" fill="none" stroke-width="0.8"/><path class="gis-ink" d="M 92.8 291.5 L 86.5 287.6 L 80.6 283.2 L 75.0 278.3 L 69.9 272.8 L 65.2 266.9 L 61.0 260.6 L 57.3 253.9 L 54.0 246.8 L 51.3 239.3 L 49.1 231.6 L 47.4 223.6 L 46.3 215.4 L 45.8 207.0 L 45.8 198.4 L 46.3 189.8 L 47.4 181.1 L 49.1 172.4 L 51.3 163.7 L 54.0 155.1 L 57.3 146.6 L 61.0 138.2 L 65.2 130.1 L 69.9 122.1 L 75.0 114.5 L 80.6 107.1 L 86.5 100.1 L 92.8 93.5 L 99.4 87.3 L 106.3 81.5 L 113.5 76.2 L 120.9 71.4 L 128.5 67.2 L 136.2 63.4 L 144.1 60.2 L 152.0 57.6 L 160.0 55.6" fill="none" stroke-width="1"/><path class="gis-muted" stroke-dasharray="3 4" d="M 160.0 300.4 L 155.4 303.1 L 150.8 305.2 L 146.3 306.6 L 141.8 307.5 L 137.4 307.7 L 133.2 307.2" fill="none" stroke-width="0.8"/><path class="gis-ink" d="M 133.2 307.2 L 129.0 306.2 L 125.0 304.5 L 121.2 302.2 L 117.6 299.3 L 114.2 295.8 L 111.0 291.7 L 108.0 287.1 L 105.3 281.9 L 102.8 276.3 L 100.7 270.1 L 98.8 263.6 L 97.2 256.5 L 96.0 249.2 L 95.0 241.4 L 94.4 233.4 L 94.0 225.1 L 94.0 216.5 L 94.4 207.8 L 95.0 198.9 L 96.0 189.9 L 97.2 180.9 L 98.8 171.9 L 100.7 162.8 L 102.8 153.9 L 105.3 145.1 L 108.0 136.4 L 111.0 127.9 L 114.2 119.7 L 117.6 111.8 L 121.2 104.2 L 125.0 96.9 L 129.0 90.0 L 133.2 83.6 L 137.4 77.6 L 141.8 72.2 L 146.3 67.2 L 150.8 62.8 L 155.4 58.9 L 160.0 55.6" fill="none" stroke-width="1"/><path class="gis-muted" stroke-dasharray="3 4" d="M 160.0 300.4 L 160.0 303.5 L 160.0 306.1 L 160.0 308.0 L 160.0 309.3 L 160.0 309.9" fill="none" stroke-width="0.8"/><path class="gis-accent" d="M 160.0 309.9 L 160.0 309.9 L 160.0 309.3 L 160.0 308.0 L 160.0 306.1 L 160.0 303.5 L 160.0 300.4 L 160.0 296.6 L 160.0 292.3 L 160.0 287.4 L 160.0 282.0 L 160.0 276.1 L 160.0 269.7 L 160.0 262.8 L 160.0 255.6 L 160.0 247.9 L 160.0 240.0 L 160.0 231.7 L 160.0 223.1 L 160.0 214.4 L 160.0 205.4 L 160.0 196.4 L 160.0 187.2 L 160.0 178.0 L 160.0 168.8 L 160.0 159.6 L 160.0 150.6 L 160.0 141.6 L 160.0 132.9 L 160.0 124.3 L 160.0 116.0 L 160.0 108.1 L 160.0 100.4 L 160.0 93.2 L 160.0 86.3 L 160.0 79.9 L 160.0 74.0 L 160.0 68.6 L 160.0 63.7 L 160.0 59.4 L 160.0 55.6" fill="none"/><path class="gis-muted" stroke-dasharray="3 4" d="M 160.0 300.4 L 164.6 303.1 L 169.2 305.2 L 173.7 306.6 L 178.2 307.5 L 182.6 307.7 L 186.8 307.2" fill="none" stroke-width="0.8"/><path class="gis-ink" d="M 186.8 307.2 L 191.0 306.2 L 195.0 304.5 L 198.8 302.2 L 202.4 299.3 L 205.8 295.8 L 209.0 291.7 L 212.0 287.1 L 214.7 281.9 L 217.2 276.3 L 219.3 270.1 L 221.2 263.6 L 222.8 256.5 L 224.0 249.2 L 225.0 241.4 L 225.6 233.4 L 226.0 225.1 L 226.0 216.5 L 225.6 207.8 L 225.0 198.9 L 224.0 189.9 L 222.8 180.9 L 221.2 171.9 L 219.3 162.8 L 217.2 153.9 L 214.7 145.1 L 212.0 136.4 L 209.0 127.9 L 205.8 119.7 L 202.4 111.8 L 198.8 104.2 L 195.0 96.9 L 191.0 90.0 L 186.8 83.6 L 182.6 77.6 L 178.2 72.2 L 173.7 67.2 L 169.2 62.8 L 164.6 58.9 L 160.0 55.6" fill="none" stroke-width="1"/><path class="gis-muted" stroke-dasharray="3 4" d="M 160.0 300.4 L 168.0 301.8 L 175.9 302.6 L 183.8 302.9 L 191.5 302.5 L 199.1 301.5 L 206.5 299.9 L 213.7 297.7 L 220.6 294.9 L 227.2 291.5" fill="none" stroke-width="0.8"/><path class="gis-ink" d="M 227.2 291.5 L 233.5 287.6 L 239.4 283.2 L 245.0 278.3 L 250.1 272.8 L 254.8 266.9 L 259.0 260.6 L 262.7 253.9 L 266.0 246.8 L 268.7 239.3 L 270.9 231.6 L 272.6 223.6 L 273.7 215.4 L 274.2 207.0 L 274.2 198.4 L 273.7 189.8 L 272.6 181.1 L 270.9 172.4 L 268.7 163.7 L 266.0 155.1 L 262.7 146.6 L 259.0 138.2 L 254.8 130.1 L 250.1 122.1 L 245.0 114.5 L 239.4 107.1 L 233.5 100.1 L 227.2 93.5 L 220.6 87.3 L 213.7 81.5 L 206.5 76.2 L 199.1 71.4 L 191.5 67.2 L 183.8 63.4 L 175.9 60.2 L 168.0 57.6 L 160.0 55.6" fill="none" stroke-width="1"/><path class="gis-muted" stroke-dasharray="3 4" d="M 160.0 300.4 L 169.2 300.1 L 178.4 299.2 L 187.4 297.7 L 196.4 295.6 L 205.1 293.0 L 213.7 289.8 L 222.0 286.1 L 229.9 281.8 L 237.6 277.0 L 244.8 271.8 L 251.7 266.0 L 258.1 259.9 L 264.0 253.3 L 269.4 246.4 L 274.3 239.2 L 278.6 231.7 L 282.4 223.8 L 285.5 215.8 L 288.1 207.6 L 290.0 199.3 L 291.3 190.8 L 291.9 182.3" fill="none" stroke-width="0.8"/><path class="gis-ink" d="M 291.9 182.3 L 291.9 173.7 L 291.3 165.2 L 290.0 156.7 L 288.1 148.4 L 285.5 140.2 L 282.4 132.2 L 278.6 124.3 L 274.3 116.8 L 269.4 109.6 L 264.0 102.7 L 258.1 96.1 L 251.7 90.0 L 244.8 84.2 L 237.6 79.0 L 229.9 74.2 L 222.0 69.9 L 213.7 66.2 L 205.1 63.0 L 196.4 60.4 L 187.4 58.3 L 178.4 56.8 L 169.2 55.9 L 160.0 55.6" fill="none" stroke-width="1"/><path class="gis-muted" stroke-dasharray="3 4" d="M 160.0 300.4 L 168.0 298.4 L 175.9 295.8 L 183.8 292.6 L 191.5 288.8 L 199.1 284.6 L 206.5 279.8 L 213.7 274.5 L 220.6 268.7 L 227.2 262.5 L 233.5 255.9 L 239.4 248.9 L 245.0 241.5 L 250.1 233.9 L 254.8 225.9 L 259.0 217.8 L 262.7 209.4 L 266.0 200.9 L 268.7 192.3 L 270.9 183.6 L 272.6 174.9 L 273.7 166.2 L 274.2 157.6 L 274.2 149.0 L 273.7 140.6 L 272.6 132.4 L 270.9 124.4 L 268.7 116.7 L 266.0 109.2 L 262.7 102.1 L 259.0 95.4 L 254.8 89.1 L 250.1 83.2 L 245.0 77.7 L 239.4 72.8 L 233.5 68.4" fill="none" stroke-width="0.8"/><path class="gis-ink" d="M 233.5 68.4 L 227.2 64.5 L 220.6 61.1 L 213.7 58.3 L 206.5 56.1 L 199.1 54.5 L 191.5 53.5 L 183.8 53.1 L 175.9 53.4 L 168.0 54.2 L 160.0 55.6" fill="none" stroke-width="1"/><path class="gis-muted" stroke-dasharray="3 4" d="M 160.0 300.4 L 164.6 297.1 L 169.2 293.2 L 173.7 288.8 L 178.2 283.8 L 182.6 278.4 L 186.8 272.4 L 191.0 266.0 L 195.0 259.1 L 198.8 251.8 L 202.4 244.2 L 205.8 236.3 L 209.0 228.1 L 212.0 219.6 L 214.7 210.9 L 217.2 202.1 L 219.3 193.2 L 221.2 184.1 L 222.8 175.1 L 224.0 166.1 L 225.0 157.1 L 225.6 148.2 L 226.0 139.5 L 226.0 130.9 L 225.6 122.6 L 225.0 114.6 L 224.0 106.8 L 222.8 99.5 L 221.2 92.4 L 219.3 85.9 L 217.2 79.7 L 214.7 74.1 L 212.0 68.9 L 209.0 64.3 L 205.8 60.2 L 202.4 56.7 L 198.8 53.8 L 195.0 51.5 L 191.0 49.8" fill="none" stroke-width="0.8"/><path class="gis-ink" d="M 191.0 49.8 L 186.8 48.8 L 182.6 48.3 L 178.2 48.5 L 173.7 49.4 L 169.2 50.8 L 164.6 52.9 L 160.0 55.6" fill="none" stroke-width="1"/><circle class="gis-accent gis-fill-accent" cx="160" cy="56" r="6"/><text class="gis-label-md gis-halo" x="160" y="38" text-anchor="middle">all 360 meet here</text><circle class="gis-accent gis-fill-accent" cx="160" cy="300" r="6"/><text class="gis-label-md gis-halo" x="160" y="332" text-anchor="middle">and here</text><text class="gis-label-mono" x="340" y="72">Meridians</text><text class="gis-label-md" x="340" y="114">Half-circles of constant</text><text class="gis-label-md" x="340" y="142">longitude, every 30°.</text><text class="gis-label-md" x="340" y="184">Not parallel at all:</text><text class="gis-label-md" x="340" y="212">widest at the equator,</text><text class="gis-label-md" x="340" y="240">converging until all 360</text><text class="gis-label-md" x="340" y="268">pass through two points.</text><text class="gis-label-md" x="340" y="310">Zero is an agreement.</text><text class="gis-label-md" x="340" y="338">Greenwich, by consent.</text><circle class="gis-muted" cx="90" cy="400" r="46" fill="none"/><line class="gis-ink" x1="90" y1="400" x2="90.0" y2="446.0" stroke-width="1"/><line class="gis-ink" x1="90" y1="400" x2="67.0" y2="439.8" stroke-width="1"/><line class="gis-ink" x1="90" y1="400" x2="50.2" y2="423.0" stroke-width="1"/><line class="gis-ink" x1="90" y1="400" x2="44.0" y2="400.0" stroke-width="1"/><line class="gis-ink" x1="90" y1="400" x2="50.2" y2="377.0" stroke-width="1"/><line class="gis-ink" x1="90" y1="400" x2="67.0" y2="360.2" stroke-width="1"/><line class="gis-ink" x1="90" y1="400" x2="90.0" y2="354.0" stroke-width="1"/><line class="gis-ink" x1="90" y1="400" x2="113.0" y2="360.2" stroke-width="1"/><line class="gis-ink" x1="90" y1="400" x2="129.8" y2="377.0" stroke-width="1"/><line class="gis-ink" x1="90" y1="400" x2="136.0" y2="400.0" stroke-width="1"/><line class="gis-ink" x1="90" y1="400" x2="129.8" y2="423.0" stroke-width="1"/><line class="gis-ink" x1="90" y1="400" x2="113.0" y2="439.8" stroke-width="1"/><line class="gis-accent" x1="90" y1="400" x2="90.0" y2="354.0"/><circle class="gis-accent gis-fill-accent" cx="90" cy="400" r="5"/><text class="gis-label-md" x="152" y="396">seen from straight above the</text><text class="gis-label-md" x="152" y="424">pole: spokes, not rails.</text></svg>
<figcaption>Because meridians converge, one degree of longitude is not a distance until you say
where you are standing. At the equator it is about 111 km; in Belgium about 71; at the pole itself
it is nothing at all. The next section is that arithmetic.</figcaption>
</figure>

Our fountain is at roughly **50.4851° N, 5.8983° E**. As a pair of signed numbers, that is
`50.4851, 5.8983`, positive latitude means north, positive longitude means east. A fountain in
Chile would have two negative numbers; one in Nairobi would have a small negative latitude and a
positive longitude.

<figure class="gis-fig gis-photo" markdown="1">
![A stone spring set into a wall, water running from a short iron spout into a shallow basin cut in the rock. The name SAUVENIERE is carved into the stone above the spout, and the stone around it is stained orange by the iron in the water. Fallen leaves lie in the basin and on the wet paving in front of it.](../../assets/photos/pouhon-la-sauveniere-spa.jpg)

<figcaption>This is the fountain. The Pouhon La Sauvenière in Spa, an iron-rich mineral spring
that has been running since long before anybody thought to give it coordinates, and the worked
example every chapter of this course follows. It is
<a href="https://www.openstreetmap.org/node/6863042080"><code>node/6863042080</code></a> in
OpenStreetMap and a row in this project's own catalog, both at <code>50.4851, 5.8983</code>. Note
what the picture can tell you and what it cannot: the name is cut into the rock, and the two numbers
are not. A place knows what it is called. It does not know where it is.<br>
Photograph by <a href="https://commons.wikimedia.org/wiki/User:Romaine">Romaine</a>,
<a href="https://commons.wikimedia.org/wiki/File:Spa-Source_de_la_Sauveni%C3%A8re_(1).jpg">via
Wikimedia Commons</a>, released under CC0.</figcaption>
</figure>

Written out in a sentence, the units are degrees, and a degree divides further. You will still meet
the old sexagesimal notation, `50° 29' 21.8" N`, on signposts and in camera metadata. Nothing in
this codebase uses it: everything here is decimal degrees, all the way down.

### The trap: which number comes first

This is the single most common bug in beginner GIS code, and it is worth burning in now, because it
will bite you at least once anyway.

**Humans say "lat, long". Most software wants longitude first.**

The reason is boring, which is exactly why it catches people out. Software treats a position as a
point on a plane, and on a plane the horizontal axis `x` conventionally comes before the vertical
axis `y`. Longitude is the east-west one, so longitude is `x`. Latitude is the north-south one, so
latitude is `y`. Hence `(x, y)` = `(longitude, latitude)`.

So:

- **GeoJSON** (chapter 2) is defined by its specification as `[longitude, latitude]`. Always.
- **PostGIS**, the spatial extension to PostgreSQL, which is what turns this project's ordinary
  database into one that can store and query shapes on the Earth, follows the same rule in its
  constructors: `ST_Point(x, y)` means `ST_Point(lng, lat)`. Every `ST_`-prefixed function in this
  chapter and the rest of the series is PostGIS, not stock PostgreSQL.
- **Most maths and geometry libraries** do too, because to them these are just numbers on a plane.

And on the other side:

- **Humans**, road signs, and every "what are your coordinates?" conversation say latitude first.
- **Some map libraries** take `[lat, lng]`, Leaflet is the best-known example. This project does
  not use Leaflet; its map is MapLibre GL JS, which is longitude-first like GeoJSON. But you will
  meet the other convention as soon as you read anybody else's map code.
- **Some of this repository's own data** is written latitude-first, in human order, because a person
  typed it. In `web/src/Catalog/Command/SeedManualCatalogCommand.php` each catalog entry carries
  explicit `'lat'` and `'lng'` keys, and the route paths beside them are arrays of `[lat, lng]`
  pairs.

Here is one such entry, exactly as a person typed it:

<!-- CODE-FROM web/src/Catalog/Command/SeedManualCatalogCommand.php -->
```php
'letter' => 'N', 'name' => 'Côte de la Redoute', 'lat' => 50.49222, 'lng' => 5.69924,
```

and here is that same pin, a few dozen lines later in the same file, on its way into a geometry:

<!-- CODE-FROM web/src/Catalog/Command/SeedManualCatalogCommand.php -->
```php
'geom' => json_encode(['type' => 'Point', 'coordinates' => [$pin['lng'], $pin['lat']]], \JSON_THROW_ON_ERROR),
```

Human order in, longitude-first `coordinates` out, the same flip, inside a single file, that the
next two examples show at the seam of a request and the seam of a map.

You can see the flip happen at a real boundary in this codebase. `SpatialResolver` answers "which
region contains this point?", see `web/src/Contribution/SpatialResolver.php`,
`SpatialResolver::resolve()`. Its signature takes latitude before longitude, in human order, because
that is how the calling code thinks:

<!-- CODE-FROM web/src/Contribution/SpatialResolver.php -->
```php
public function resolve(float $lat, float $lng): array
```

The SQL it builds a few lines later flips the order:

<!-- CODE-FROM web/src/Contribution/SpatialResolver.php -->
```sql
ST_Contains(geom, ST_SetSRID(ST_Point(:lng, :lat), 4326))
```

`:lng` before `:lat`. The method is the seam where human order becomes machine order, and it is
deliberate. Open the file: the whole class is under forty lines, and the seam is visible at a
glance.

The same flip happens on the front end. In `web/assets/map/spotlight.js`, `setCircleSpotlight()` receives
a `center` in human order and reads the latitude straight out of it:

<!-- CODE-FROM web/assets/map/spotlight.js -->
```js
const n=64, lat=center[0];
```

A few lines later, the same function builds the ring it hands to MapLibre the other way round,
`[lng, lat]`, because that is what GeoJSON requires:

<!-- CODE-FROM web/assets/map/spotlight.js -->
```js
ring.push([center[1]+dLng*Math.cos(a), lat+dLat*Math.sin(a)]);
```

One function, both conventions, a few lines apart.

!!! warning "How this bug shows up"
    Swapping the two numbers rarely throws an error, because both are plain floats and both are
    plausible. `50.4851, 5.8983` reversed is `5.8983, 50.4851`, a perfectly valid position in the
    Indian Ocean off the coast of Somalia, about 6,400 km away. Your code runs, your query returns
    zero rows, and nothing tells you why. If a spatial query mysteriously finds nothing, check the
    argument order before you check anything else. Latitude can never exceed 90, so any number above
    90 in the latitude slot is a free giveaway, but only when the longitude is large enough for the
    swap to produce one.

## A degree is not a distance

Here is the second thing that trips people up, and the one that produces wrong answers rather than
empty ones.

A degree of latitude and a degree of longitude are both "one degree", but they do not cover the same
amount of ground, and only one of them is even constant.

**One degree of latitude is about 111 km, everywhere.** Going one degree north always means
travelling along a meridian, every meridian is the same size circle, and equal angles on equal
circles cut equal arcs. The Earth's pole-to-pole circumference is about 40,008 km; divide by 360 and
you get 111.1 km. That number holds in Belgium, in Kenya, and in Antarctica.

**One degree of longitude is about 111 km at the equator, and shrinks from there.** Going one degree
east means travelling along a parallel, and parallels are not all the same size. The equator is a
full-size circle around the planet, about 40,075 km, so one degree of it is 111.3 km. The parallel
at 50° north is a much smaller circle, because it is a slice taken near the top of the sphere. All
360 degrees of longitude still have to fit around that smaller circle, so each degree is shorter:

<!-- CODE-ILLUSTRATIVE formula, hand-written -->
```text
one degree of longitude ≈ 111.32 km × cos(latitude)
```

At the equator, `cos(0°) = 1`, so you get the full 111.3 km. At 50° north, `cos(50°) ≈ 0.643`, so
you get about 71 km. At our fountain's latitude of 50.4851°, about 70.8 km. At 70° north, about
38 km. At the pole itself, `cos(90°) = 0`: all 360 degrees of longitude collapse into a single
point, and "one degree east" means standing still.

The figure below shows why. The strip between two meridians is the same number of degrees wide all
the way from pole to pole, but the ground it covers narrows the whole way up.

<figure class="gis-fig"><svg viewBox="0 0 640 710" role="img" aria-labelledby="f1-t f1-d" xmlns="http://www.w3.org/2000/svg"><title id="f1-t">One degree of longitude at the equator and at 50 degrees north</title><desc id="f1-d">A globe seen from the side. Meridians, the lines of constant longitude, are drawn as curves that spread apart at the equator and converge to a single point at each pole. The strip of surface between two neighbouring meridians is tinted from pole to pole; it is at its widest on the equator and narrows steadily toward the top and bottom of the globe. On the equator that strip is marked as about 111 kilometres of ground for one degree; on the 50 degrees north parallel the very same strip is marked as about 71 kilometres for one degree. Below the globe, three bars compare the three distances at the same scale: one degree of latitude is 111 kilometres at any latitude; one degree of longitude is 111 kilometres at the equator; one degree of longitude at 50 degrees north is only 71 kilometres, and a double-headed arrow marks the missing length as 36 per cent shorter.</desc><defs><marker id="gis-arrow-f1" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="14" markerHeight="14" markerUnits="userSpaceOnUse" orient="auto-start-reverse"><path d="M 0 0 L 10 5 L 0 10 Z"/></marker></defs><path class="gis-fill-ochre" fill-opacity=".22" d="M 250 70 A 36.23 140 0 0 1 250 350 Z"/><line class="gis-muted" x1="202.1" y1="78.4" x2="297.9" y2="78.4"/><line class="gis-muted" x1="142.75" y1="120" x2="357.25" y2="120"/><line class="gis-muted" x1="118.4" y1="162.1" x2="381.6" y2="162.1"/><line class="gis-muted" x1="118.4" y1="257.9" x2="381.6" y2="257.9"/><line class="gis-muted" x1="142.75" y1="300" x2="357.25" y2="300"/><line class="gis-muted" x1="202.1" y1="341.6" x2="297.9" y2="341.6"/><line class="gis-muted" x1="250" y1="70" x2="250" y2="350"/><path class="gis-muted" d="M 250 70 A 36.23 140 0 0 1 250 350"/><path class="gis-muted" d="M 250 70 A 36.23 140 0 0 0 250 350"/><path class="gis-muted" d="M 250 70 A 70 140 0 0 1 250 350"/><path class="gis-muted" d="M 250 70 A 70 140 0 0 0 250 350"/><path class="gis-muted" d="M 250 70 A 98.99 140 0 0 1 250 350"/><path class="gis-muted" d="M 250 70 A 98.99 140 0 0 0 250 350"/><path class="gis-muted" d="M 250 70 A 121.24 140 0 0 1 250 350"/><path class="gis-muted" d="M 250 70 A 121.24 140 0 0 0 250 350"/><path class="gis-muted" d="M 250 70 A 135.23 140 0 0 1 250 350"/><path class="gis-muted" d="M 250 70 A 135.23 140 0 0 0 250 350"/><circle class="gis-ink" cx="250" cy="210" r="140"/><line class="gis-ink" x1="110" y1="210" x2="390" y2="210"/><line class="gis-ink" x1="160" y1="102.75" x2="340" y2="102.75"/><line class="gis-accent" stroke-width="6" x1="250" y1="210" x2="286.23" y2="210"/><line class="gis-accent" x1="250" y1="199" x2="250" y2="221"/><line class="gis-accent" x1="286.23" y1="199" x2="286.23" y2="221"/><line class="gis-accent" stroke-width="6" x1="250" y1="102.75" x2="273.29" y2="102.75"/><line class="gis-accent" x1="250" y1="91.75" x2="250" y2="113.75"/><line class="gis-accent" x1="273.29" y1="91.75" x2="273.29" y2="113.75"/><text class="gis-label-sm" x="250" y="44" text-anchor="middle">meridians meet at the poles</text><text class="gis-label-sm" x="104" y="202" text-anchor="end">equator</text><text class="gis-label-sm" x="104" y="232" text-anchor="end">0°</text><text class="gis-label-sm gis-halo" x="146" y="110" text-anchor="end">50° N</text><text class="gis-halo" x="294" y="202">1° ≈ 111 km</text><text class="gis-halo" x="288" y="94">1° ≈ 71 km</text><text x="20" y="400">The same one degree, measured</text><text class="gis-label-sm" x="20" y="448">1° of latitude, at any latitude</text><rect class="gis-fill-spruce" x="20" y="458" width="480" height="26"/><text class="gis-label-sm" x="512" y="479">111 km</text><text class="gis-label-sm" x="20" y="528">1° of longitude, at the equator</text><rect class="gis-fill-accent" x="20" y="538" width="480" height="26"/><text class="gis-label-sm" x="512" y="559">111 km</text><text class="gis-label-sm" x="20" y="608">1° of longitude, at 50° N</text><rect class="gis-fill-accent" x="20" y="618" width="307" height="26"/><text class="gis-label-sm" x="339" y="639">71 km</text><line class="gis-muted" stroke-dasharray="4 5" x1="500" y1="458" x2="500" y2="672"/><line class="gis-muted" stroke-dasharray="4 5" x1="327" y1="644" x2="327" y2="672"/><line class="gis-ink" x1="333" y1="666" x2="494" y2="666" marker-start="url(#gis-arrow-f1)" marker-end="url(#gis-arrow-f1)"/><text class="gis-label-sm" x="413" y="700" text-anchor="middle">36% shorter</text></svg><figcaption>Meridians are furthest apart on the equator and meet at the poles, so the ground covered by one degree of longitude shrinks as you move away from the equator. A degree of latitude does not: the bars are measured against the same scale. That is why a “0.1 degree box” is not a fixed patch of ground, the same two numbers in the code enclose a different amount of the world depending on where you are. The tinted strip is drawn far wider than a single degree so that it is visible at all; the <em>ratio</em> between the two marked spans is exact, and equals cos 50° ≈ 0.64.</figcaption></figure>

The immediate consequence is that a "0.1 degree box" is not a fixed size. Near the equator it is
about 11 km by 11 km. At our fountain it is about 11.1 km tall and 7.1 km wide. In northern Norway,
at 70°, it is about 11.1 km tall and 3.8 km wide, the same box in the code, a third of the ground.

This has a direct effect on code you will be tempted to write. A query like this looks like it asks
for everything within about 5 km:

<!-- CODE-ILLUSTRATIVE naive fixed-degree bounding box, not our code -->
```sql
-- Wrong, and wrong by a different amount depending on where you run it.
WHERE lat BETWEEN :lat - 0.045 AND :lat + 0.045
  AND lng BETWEEN :lng - 0.045 AND :lng + 0.045
```

It does not. It asks for a rectangle roughly 10 km tall and, at our fountain, about 6.4 km wide,
and if the same query runs for a rider in Norway, that rectangle is about 3.4 km wide instead. The
same numbers, the same code, a different question depending on latitude.

For the same reason, you cannot take two positions in degrees, apply Pythagoras, and call the result
a distance. The two axes are not in the same units as each other, and the longitude axis is not even
in consistent units with itself.

Where this project genuinely does need a rough distance in degrees, it applies the `cos(latitude)`
correction on purpose rather than hoping it does not matter. `setCircleSpotlight()` in
`web/assets/map/spotlight.js` draws the "my area" circle on the map, and to do that it converts a radius
in kilometres into a step in degrees:

<!-- CODE-FROM web/assets/map/spotlight.js -->
```js
const cosLat=Math.max(0.01, Math.cos(lat*Math.PI/180));
const dLat = rkm/111.32, dLng = rkm/(111.32*cosLat);
```

The latitude step is the radius over 111.32; the longitude step is the radius over 111.32 times the
cosine of the latitude. The line above it clamps that cosine to a small minimum, because near the
poles the cosine goes to zero and the longitude step would go to infinity. Every idea in this
section is in those two lines.

Chapter 3 is entirely about how to ask for a real distance instead, the proper way, in the database,
without hand-rolled trigonometry. For now, the thing to carry forward is smaller and simpler:
**degrees are angles, not lengths.**

## Why maps lie

The fountain is on a curved surface. Your screen is flat. Somewhere between the two, something has
to give.

A **projection** is a rule for turning a position on the curved Earth into a position on a flat
plane. Every map you have ever looked at applied one, whether or not it said so.

There is no perfect projection, and that is not a software problem waiting for a better algorithm.
It is a proved fact about geometry: a sphere and a plane have genuinely different curvature, so no
rule can flatten one onto the other without stretching, tearing, or both. The everyday version of
this is peeling an orange and trying to press the peel flat, it splits, or it stretches, and you
get to choose which.

So every projection distorts at least one of four things:

| Property | What it means | Kept by |
|---|---|---|
| **Area** | Two regions that are equally big really look equally big | equal-area projections |
| **Shape** | Angles are locally correct, so small shapes are not skewed | conformal projections |
| **Distance** | Measured lengths match reality | only along particular lines, never everywhere |
| **Direction** | A bearing on the map is a bearing on the ground | some, at a cost elsewhere |

You do not get to keep all four. You pick the lie you can live with, for the job you are doing.

Two of those choices matter here.

**Plotting latitude and longitude straight onto the page**, as if latitude were `y` and longitude
were `x`, is itself a projection. It has a name, plate carrée, or the equirectangular projection,
and it is the one people apply by accident, because it looks like no projection has been applied at
all. It is easy and it is fine for a rough sketch, but it stretches everything east-west as you move
away from the equator, by exactly the `1 / cos(latitude)` factor from the previous section.

**Web Mercator** is the projection tiled web maps use, and it is conformal: it keeps shapes and
angles locally correct, so a town looks like the right shape and a right-angled junction still looks
like a right angle. It pays for that by getting area badly wrong. Away from the equator it stretches
north-south by the *same* factor it stretches east-west, which is why the shapes survive, and why
the areas balloon by that factor squared. Web maps use it anyway, because north is always up, a tile
stays square at every zoom level, and a constant compass bearing is a straight line on the map. For
a **slippy map**, the pan-and-drag, zoom-with-the-wheel kind every web map is now, as opposed to a
fixed picture, that is worth more than honest area.

The usual demonstration is Greenland. On a Web Mercator map it looks about the size of Africa.
Africa is roughly fourteen times larger.

<figure class="gis-fig"><svg viewBox="0 0 640 640" role="img" aria-labelledby="f2-t f2-d" xmlns="http://www.w3.org/2000/svg"><title id="f2-t">The same two landmasses drawn in EPSG:4326 and in EPSG:3857</title><desc id="f2-d">Two maps of the North Atlantic side by side, covering the same range of longitude and the same range of latitude, drawn at the same horizontal scale. The left map, labelled EPSG:4326, plots degrees straight onto the page: its parallels at 40, 60 and 80 degrees north are evenly spaced, and the whole map is short and wide. Greenland appears roughly eleven times the area of France. The right map, labelled EPSG:3857, uses Web Mercator: the same parallels spread further and further apart toward the top, so the map is nearly three times as tall, and the tinted band between 60 and 80 degrees north is more than three times its height on the left. Greenland is enormously inflated and appears roughly thirty times the area of France. In reality Greenland is about four times the area of France. A key below the maps identifies the tinted band, the pale outlined shape as Greenland, and the small orange shape as France.</desc><text class="gis-label-mono" x="52" y="296">EPSG:4326</text><text class="gis-label-sm" x="52" y="326">degrees plotted flat</text><line class="gis-muted" x1="52" y1="455.4" x2="292" y2="455.4"/><line class="gis-muted" x1="52" y1="428.7" x2="292" y2="428.7"/><line class="gis-muted" x1="52" y1="402" x2="292" y2="402"/><line class="gis-muted" x1="52" y1="375.4" x2="292" y2="375.4"/><line class="gis-muted" x1="52" y1="348.7" x2="292" y2="348.7"/><line class="gis-muted" x1="92" y1="335.4" x2="92" y2="468.7"/><line class="gis-muted" x1="145.3" y1="335.4" x2="145.3" y2="468.7"/><line class="gis-muted" x1="198.7" y1="335.4" x2="198.7" y2="468.7"/><line class="gis-muted" x1="252" y1="335.4" x2="252" y2="468.7"/><rect class="gis-fill-ochre" fill-opacity=".22" x="52" y="348.7" width="240" height="53.3"/><path class="gis-ink gis-fill-paper" d="M 162.7 339.1 L 185.3 342.8 L 200.0 348.2 L 202.7 355.4 L 196.0 363.4 L 192.0 371.4 L 182.7 378.0 L 168.0 383.4 L 150.7 390.0 L 134.9 402.6 L 122.7 399.9 L 116.0 393.5 L 111.2 386.6 L 108.5 380.2 L 104.0 374.0 L 98.7 369.5 L 89.3 364.7 L 74.7 359.4 L 58.7 354.6 L 70.7 349.2 L 94.7 345.2 L 129.3 341.5 Z"/><path class="gis-ink gis-fill-accent" d="M 239.2 433.0 L 248.0 430.0 L 256.3 426.0 L 265.1 429.2 L 272.3 431.6 L 271.2 435.4 L 270.4 439.4 L 270.7 443.9 L 266.7 446.8 L 260.0 447.4 L 260.5 449.0 L 247.2 446.6 L 248.8 440.7 L 246.4 436.7 Z"/><rect class="gis-ink" x="52" y="335.4" width="240" height="133.3"/><text class="gis-label-sm" x="44" y="463.4" text-anchor="end">40°</text><text class="gis-label-sm" x="44" y="410" text-anchor="end">60°</text><text class="gis-label-sm" x="44" y="356.7" text-anchor="end">80°</text><text class="gis-label-mono" x="392" y="50">EPSG:3857</text><text class="gis-label-sm" x="392" y="80">Web Mercator</text><line class="gis-muted" x1="392" y1="451.9" x2="632" y2="451.9"/><line class="gis-muted" x1="392" y1="414" x2="632" y2="414"/><line class="gis-muted" x1="392" y1="367.2" x2="632" y2="367.2"/><line class="gis-muted" x1="392" y1="303.3" x2="632" y2="303.3"/><line class="gis-muted" x1="392" y1="196.2" x2="632" y2="196.2"/><line class="gis-muted" x1="432" y1="90" x2="432" y2="468.7"/><line class="gis-muted" x1="485.3" y1="90" x2="485.3" y2="468.7"/><line class="gis-muted" x1="538.7" y1="90" x2="538.7" y2="468.7"/><line class="gis-muted" x1="592" y1="90" x2="592" y2="468.7"/><rect class="gis-fill-ochre" fill-opacity=".22" x="392" y="196.2" width="240" height="171"/><path class="gis-ink gis-fill-paper" d="M 502.7 127.8 L 525.3 158.1 L 540.0 193.1 L 542.7 230.5 L 536.0 263.7 L 532.0 291.2 L 522.7 310.9 L 508.0 325.2 L 490.7 341.4 L 474.9 368.3 L 462.7 362.9 L 456.0 349.3 L 451.2 333.1 L 448.5 316.8 L 444.0 299.4 L 438.7 285.2 L 429.3 268.6 L 414.7 248.0 L 398.7 226.8 L 410.7 199.3 L 434.7 174.8 L 469.3 147.9 Z"/><path class="gis-ink gis-fill-accent" d="M 579.2 420.6 L 588.0 416.1 L 596.3 409.8 L 605.1 414.8 L 612.3 418.5 L 611.2 424.1 L 610.4 430.0 L 610.7 436.4 L 606.7 440.5 L 600.0 441.2 L 600.5 443.4 L 587.2 440.1 L 588.8 431.9 L 586.4 426.1 Z"/><rect class="gis-ink" x="392" y="90" width="240" height="378.7"/><text class="gis-label-sm" x="384" y="459.9" text-anchor="end">40°</text><text class="gis-label-sm" x="384" y="375.2" text-anchor="end">60°</text><text class="gis-label-sm" x="384" y="204.2" text-anchor="end">80°</text><text class="gis-label-sm" x="172" y="500" text-anchor="middle">Greenland looks</text><text class="gis-label-sm" x="172" y="528" text-anchor="middle">≈ 11× France</text><text class="gis-label-sm" x="512" y="500" text-anchor="middle">Greenland looks</text><text class="gis-label-sm" x="512" y="528" text-anchor="middle">≈ 30× France</text><text x="320" y="572" text-anchor="middle">In reality, Greenland is about 4× France.</text><rect class="gis-ink gis-fill-ochre" fill-opacity=".22" x="20" y="596" width="26" height="26"/><text class="gis-label-sm" x="54" y="616">60°–80° N</text><rect class="gis-ink gis-fill-paper" x="190" y="596" width="26" height="26"/><text class="gis-label-sm" x="224" y="616">Greenland</text><rect class="gis-ink gis-fill-accent" x="360" y="596" width="26" height="26"/><text class="gis-label-sm" x="394" y="616">France</text></svg><figcaption>Both maps cover the same longitudes and the same latitudes, at the same horizontal scale, so every difference you see is what the projection did to the north-south axis. On the left the parallels are evenly spaced; on the right they spread apart the further north they go, which is what stretches Greenland. The tinted band is 60°–80° N in both: on the right it is more than three times as tall. Neither map is “wrong”. They distort different things, and that is exactly why the numbers cannot travel on their own, something has to say which system they are in.</figcaption></figure>

## SRIDs: naming the system

`50.4851, 5.8983` is not a place. It is two numbers. It only becomes a place once you also know
which coordinate system it belongs to, which model of the Earth's shape, which starting lines,
which units.

An **SRID**, Spatial Reference IDentifier, is an integer that names that system. PostGIS stores an
SRID alongside every geometry it holds, so a value in the database always carries its own answer to
"what do these numbers mean?".

Most SRIDs are simply the identifiers from the **EPSG registry**, a long-running public catalogue of
coordinate systems, each with its own number. When you see `EPSG:4326` written in documentation and
`4326` written in SQL, they are the same thing.

Two of them come up constantly.

**EPSG:4326, WGS84, in degrees.** Latitude and longitude as described at the top of this page,
measured against WGS84, a specific agreed model of the Earth's shape. This is what a GPS receiver
gives you, what a GPX file from a bike computer contains, what OpenStreetMap stores, and what
GeoJSON is defined to use. The units are degrees.

**EPSG:3857, Web Mercator, in metres.** The projected plane that tiled web maps are drawn on. The
units are called metres, and near the equator they behave like metres, but they are not ground
distances. Web Mercator stretches by `1 / cos(latitude)`, so at 60° north one projected metre
corresponds to roughly half a metre of actual ground. Measuring in EPSG:3857 and reporting the
answer in metres is a real and popular bug.

Web Mercator also cannot represent the poles at all: the formula sends the projected `y` value off
to infinity as latitude approaches 90. The convention is to cut the world off at ±85.05112878°,
which is not an arbitrary number, it is precisely the latitude at which the projected height equals
the projected width, making the whole world a square. That squareness is what lets the tile scheme
in chapter 7 divide the world into four, then four again, forever.

Two more things are worth knowing before you write any SQL.

First, PostGIS refuses to compare geometries with different SRIDs. That looks like an obstacle the
first time you hit it, and it is actually the system saving you from a silently wrong answer.

Second, and easy to confuse: `ST_SetSRID` and `ST_Transform` are not the same operation.
`ST_SetSRID` only *labels* a geometry, it changes the stated system and leaves every number
untouched. `ST_Transform` genuinely *converts*, recomputing the numbers from one system into
another. Using the first where you needed the second gives you a geometry that claims to be
somewhere it is not, with no error anywhere. Nothing in this repository calls `ST_Transform`,
because nothing here ever needs to leave 4326, which is the subject of the last section. That is a
claim with a shelf life, so course 2's [reprojection chapter](../gis-beyond/reprojection.md) opens
by teaching you the one-line grep that checks it rather than asking you to take it on trust.

<!-- UNANCHORED id=U01 type=general concept="on-the-fly reprojection (ST_Transform)" -->

## What we store

Every geometry column in this project is declared the same way:

<!-- CODE-FROM web/src/Catalog/Doctrine/GeometryType.php -->
```sql
geometry(Geometry, 4326)
```

Every migration's copy of that string is generated from one place, so no table can quietly get a
different one. See `web/src/Catalog/Doctrine/GeometryType.php`,
`GeometryType::getSQLDeclaration()`, a Doctrine custom type, registered as the DBAL type
`geometry` in `web/config/packages/doctrine.yaml`, whose entire job is to say what a geometry column
looks like in SQL. Every entity that stores a shape declares `#[ORM\Column(type: 'geometry')]`,
some add `nullable: true`, but none of them names a type or an SRID of its own, so the six
Doctrine-managed geometry columns that exist today, `region.geom`, `item.geom`,
`recommended_route.geom`, `heat_point.geom`, `submission.geom` and `users.base_point`, all got
their declaration from this one method. You will find the same literal string written out in each
of the migrations that created them; those are copies of this method's output, frozen at the moment
the migration was generated, not six independent decisions. One more geometry column exists in the
same database and is not in that list: `coverage_poi.geom`, which the Python pipeline creates and
owns rather than Doctrine. It carries the same SRID for the same reason. Chapter 6 covers it.

The declaration has two halves:

- **`Geometry`** is the shape type: which kind of shape the column may hold. `Geometry` is the
  permissive option, meaning any of them, a point, a line, an outline. Chapter 2 covers what those
  shapes are, and which of them each table actually holds.
- **`4326`** is the SRID. Degrees of latitude and longitude, in WGS84.

**Why 4326 and not something else.** Everything that feeds this system already speaks it. GPS
devices produce it, the GPX files riders upload contain it, OpenStreetMap publishes it, and GeoJSON,
the format geometries travel in on the way to and from PHP, is defined in terms of it. Storing
anything else would mean converting on the way in *and* converting back on the way out, on every
single row, with a fresh chance to get it wrong in each direction. Storing what the world hands you
means the conversion count is zero.

The same class shows the boundary in action. `GeometryType::convertToDatabaseValueSQL()` wraps every
write in `ST_SetSRID(ST_GeomFromGeoJSON(…), 4326)`, and `GeometryType::convertToPHPValueSQL()` wraps
every read in `ST_AsGeoJSON(…)`. Note which function that is on the write side: `ST_SetSRID`, not
`ST_Transform`. There is nothing to convert, because GeoJSON is already defined to be in this
system; the call is there to stamp the label explicitly rather than rely on a default. Chapter 2
picks that round-trip up and explains the GeoJSON half of it.

**So where does 3857 appear?** Only at the very end, on the way out to the browser, in the tile
build, and even there, we do not do the projecting ourselves. `build_pmtiles()` in
`pipeline/coverage/tiles.py` shells out to **tippecanoe**, the tile cutter, handing it our
geometries in 4326:

<!-- CODE-FROM pipeline/coverage/tiles.py -->
```python
for (letter, cc) in sorted(layer_files):
    cmd += ["-L", f"{letter.lower()}_{cc.lower()}:{layer_files[(letter, cc)]}"]
subprocess.run(cmd, check=True)
```

tippecanoe is what projects them to Web Mercator and slices the result into tiles. That is the
moment this project's data leaves 4326, and it happens inside a third-party tool, on the way out,
to a copy.

The Web Mercator formula is written out in our own code exactly once, a few functions further down
the same file:

<!-- CODE-FROM pipeline/coverage/tiles.py -->
```python
def _tile_y(lat: float, n: int) -> int:
    lat = max(min(lat, 85.05112878), -85.05112878)
    return min(n - 1, max(0, int((1 - math.asinh(math.tan(math.radians(lat))) / math.pi) / 2 * n)))
```

`_tile_y()` clamps a latitude to that same ±85.05112878 limit and returns which tile row it falls
in. It is a private helper with a single caller, `verify_pmtiles()`, the
sanity gate that runs *after* the archive is built, works out which tile ought to contain the
data's bounding box, fetches it, and checks it decodes. So `_tile_y()` is not the project
projecting anything; it is the project checking tippecanoe's homework, and it is the only place a
reader will find the maths spelled out. The database itself never holds a projected coordinate.
Chapter 7 covers the tile pyramid all of this belongs to.

## What to carry into chapter 2

- Latitude is north-south, −90 to 90. Longitude is east-west, −180 to 180.
- Humans say latitude first. GeoJSON, PostGIS and most libraries want longitude first.
- A degree of latitude is about 111 km anywhere. A degree of longitude is about 111 km at the
  equator and shrinks by `cos(latitude)` from there.
- Degrees are angles. They are not a unit of distance, and you cannot do arithmetic on them as if
  they were.
- Flattening the Earth always costs you something. Every map has chosen which error to accept.
- Numbers without an SRID are meaningless. In this codebase the SRID is always 4326, everywhere it
  is stored.

The fountain now has a position and a coordinate system to interpret it in. Next it needs a shape,
because the same column type that holds this single point also has to hold a rider's whole route and
the outline of Wallonia.


## Further reading

- [EPSG:4326 at epsg.io](https://epsg.io/4326): the registry entry for the coordinate system everything here is stored in.
- [RFC 7946, the GeoJSON specification](https://datatracker.ietf.org/doc/html/rfc7946): short, readable, and the authority for how coordinates are ordered.
- [PostGIS: spatial reference systems](https://postgis.net/docs/using_postgis_dbmanagement.html#spatial_ref_sys): what an SRID is, from the database's own manual.

## Try it

!!! tip "Hands-on: watch the swap go quiet"
    Ask the dev catalog for everything within 5 km of the fountain, the right way round and then the
    wrong way round, and watch the second answer disappear without an error. This runs against the
    live `item` table (chapter 5, [`making-it-fast.md`](making-it-fast.md#how-to-tell), shows how to
    open a `psql` session against it).

    First, longitude before latitude, `ST_Point(lng, lat)`, exactly as PostGIS wants it:

    <!-- CODE-ILLUSTRATIVE psql query against the dev catalog, correct lng-first argument order -->
    ```sql
    SELECT count(*) AS nearby
    FROM item
    WHERE ST_DWithin(geom::geography,
                      ST_SetSRID(ST_Point(5.8983, 50.4851), 4326)::geography, 5000);
    ```

    <!-- CODE-ILLUSTRATIVE SAMPLE-FROM fresh-clone; sample output on a stack seeded by `make course-data`; the count grows as the catalog does, being non-zero is the point -->
    ```text
     nearby
    --------
         56
    (1 row)
    ```

    56 is what a clone seeded by `make course-data` holds within 5 km of the fountain; import more
    data and it only goes up. The number is not the lesson, a non-zero one is.

    Now swap the two numbers into `ST_Point`, as if you had typed them in the order a human says them
    out loud, latitude first:

    <!-- CODE-ILLUSTRATIVE psql query against the dev catalog, the human lat-first order fed straight into ST_Point -->
    ```sql
    SELECT count(*) AS nearby
    FROM item
    WHERE ST_DWithin(geom::geography,
                      ST_SetSRID(ST_Point(50.4851, 5.8983), 4326)::geography, 5000);
    ```

    <!-- CODE-ILLUSTRATIVE SAMPLE-FROM any-install; sample output; stable regardless of catalog growth, the swapped point has nothing near it on Earth's dry land -->
    ```text
     nearby
    --------
          0
    (1 row)
    ```

    Same predicate, same radius, same fountain, the only change is which number went into which
    argument slot. `ST_Point(50.4851, 5.8983)` is a real, valid point, about 6,400 km from here, in
    the Indian Ocean off the coast of Somalia, the exact place the warning above named. Nothing
    threw an error, because both numbers are plausible floats in range. The query simply came back
    empty, and empty is precisely what a longitude-first bug looks like from the outside.
